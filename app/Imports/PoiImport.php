<?php

namespace App\Imports;

use App\Models\Kantor;
use App\Models\Poi;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\SkipsFailures;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithValidation;
use Throwable;

/**
 * Bulk import for POI. Columns (heading row -> snake_case key): Nama, Alamat,
 * Sektor, Sub Sektor, Area, Outlet, Bank, PIC -> nama, alamat, sektor,
 * sub_sektor, area, outlet, bank, pic.
 *
 * Deliberately lenient by design (explicit product decision, 2026-07-14):
 * **Outlet is the only field that can reject a row, and only when it's
 * blank.** A non-blank Outlet that doesn't match any existing kantor is NOT
 * a rejection — the real data has hundreds of kantor not pre-registered in
 * the system yet, and requiring someone to manually create each one first
 * would defeat the point of a bulk import. Instead, an admin's import
 * auto-creates the missing Kantor row (resolveOrCreateKantorId()) so
 * kantor_id stays a real, valid foreign key — never falls back to storing
 * the outlet name as free text, which would break every kantor-scoped
 * filter/dashboard/RBAC check in the app. admin_final is the exception: they
 * stay strictly bounded to kantor they're already assigned to (user_kantor)
 * — an unrecognized name for them is just wrong/unowned, never "a new
 * kantor to add", since introducing new kantor into the system is
 * effectively an admin-level action. Every other field has a safe fallback
 * instead of rejecting the row:
 *   - nama/alamat blank -> '-' placeholder (never blank in the UI/search).
 *   - sektor / area -> stored as-is, whatever the file says (both are plain
 *     VARCHAR columns, not restricted to Poi::SEKTOR_OPTIONS/AREA_OPTIONS —
 *     that curated list is only enforced on the manual create/edit form's
 *     dropdown; import data is internal and doesn't need to match it). Only
 *     truly blank sektor falls back to 'Lainnya' since the column is NOT
 *     NULL; blank area stays null (already optional everywhere it's used).
 *   - bank not one of the 3 canonical status_mitra values -> falls back to
 *     Poi::BELUM_BERMITRA_BNI, NOT any other bucket: that's the only default
 *     that keeps the POI prospectable (visible to sales, countable in
 *     dashboard totals) instead of silently vanishing from every stat that
 *     hard-compares status_mitra (Dashboard's BNI/Non split, the "belum
 *     bermitra" pool KunjunganController offers sales to visit, the status
 *     badge). status_mitra stays a real ENUM (see migration) — unlike
 *     sektor/area it's hard-compared all over the app, so it can't become
 *     free text without breaking that math; "not a recognized BNI value" is
 *     just treated as "not a BNI partner yet", which is the correct bucket.
 * Outlet/bank matching is case-insensitive and tolerant of extra whitespace
 * (normalize()), so formatting differences in a hand-built source file don't
 * trip either the fallback or the outlet rejection.
 *
 * sub_sektor additionally treats a literal "nan" as blank (cleanSubSektor())
 * — a common artifact of blank cells round-tripping through pandas/NaN-aware
 * tooling before landing back in Excel/CSV.
 *
 * Deliberately NOT using WithBatchInserts: with it, ModelManager::massFlush()
 * bypasses Eloquent (raw ->insert()/upsert()), which would silently skip
 * PoiObserver and leave dashboard_summary stale. Plain ToModel (no batch
 * inserts) makes ModelManager::singleFlush() call $model->saveOrFail() per
 * row instead, so every row still goes through Eloquent events.
 *
 * WithChunkReading only paginates how the worksheet itself is read off disk
 * (memory safety for the ~184k row production file) — it does not change the
 * per-row validate -> model -> save pipeline above.
 *
 * The real template (Template_Import_POI_PROMIS.xlsx) has 3 sheets
 * ("Petunjuk" instructions, "Data POI" the real data, "_lookup" dropdown
 * source lists) — WithMultipleSheets + $sheetName restricts processing to
 * one sheet so "Petunjuk"/"_lookup" never get misread as data rows. The
 * controller resolves $sheetName to the file's only sheet when it has just
 * one (any name), and falls back to the literal "Data POI" for multi-sheet
 * files like the official template — see PoiImportController::store().
 *
 * SkipsOnError (2026-07-15 fix): WithValidation/SkipsOnFailure only catches
 * *validation* failures — a genuine runtime exception during save (DB
 * constraint violation, dropped connection, a unique `kode` collision in
 * generateKode() despite the loop, etc.) is a completely different failure
 * mode that Maatwebsite's ModelManager::handleException() re-throws unless
 * the import also implements SkipsOnError. Without it, one bad row anywhere
 * in the file used to abort the ENTIRE WithChunkReading transaction for that
 * 500-row chunk (rolling back rows that had already saved successfully) and
 * bubble up to PoiImportController's catch, which showed a hardcoded and
 * usually-wrong "rename your sheet" message with zero log trail. onError()
 * below logs the real exception and records it in $errors() instead, and
 * importedCount() is corrected back down since model() increments it
 * optimistically before the save is known to have succeeded.
 */
class PoiImport implements SkipsEmptyRows, SkipsOnError, SkipsOnFailure, ToModel, WithChunkReading, WithHeadingRow, WithMultipleSheets, WithValidation
{
    use SkipsFailures;

    /** @var array<string, int> normalize(kantor.nama) => kantor.id */
    private array $kantorMap;

    /** @var array<string, string> normalize(value) => canonical Poi::STATUS_MITRA_OPTIONS value */
    private array $statusMitraMap;

    /** @var int[]|null kantor ids the acting user may import into; null = unrestricted (admin) */
    private ?array $allowedKantorIds;

    private int $importedCount = 0;

    /** @var Throwable[] */
    private array $errors = [];

    public function __construct(private readonly User $user, private readonly string $sheetName = 'Data POI')
    {
        $this->kantorMap = [];
        foreach (Kantor::where('kode', '!=', Kantor::SENTINEL_ALL_KODE)->pluck('id', 'nama') as $nama => $id) {
            $this->kantorMap[$this->normalize($nama)] = $id;
        }

        $this->statusMitraMap = collect(Poi::STATUS_MITRA_OPTIONS)->mapWithKeys(fn ($v) => [$this->normalize($v) => $v])->all();

        $this->allowedKantorIds = $user->isAdmin()
            ? null
            : $user->kantor()->pluck('kantor.id')->all();
    }

    public function model(array $row): ?Poi
    {
        $outletRaw = trim((string) ($row['outlet'] ?? ''));

        // Blank outlet is the only thing rules() rejects, so this only ever
        // returns null here as a defensive guard, not the normal path.
        if ($outletRaw === '') {
            return null;
        }

        $kantorId = $this->resolveOrCreateKantorId($outletRaw);

        $nama = trim((string) ($row['nama'] ?? ''));
        $alamat = trim((string) ($row['alamat'] ?? ''));
        $sektor = trim((string) ($row['sektor'] ?? ''));
        $area = $this->blankToNull($row['area'] ?? null);
        $bank = $this->statusMitraMap[$this->normalize((string) ($row['bank'] ?? ''))] ?? Poi::BELUM_BERMITRA_BNI;

        $this->importedCount++;

        return new Poi([
            'nama_poi' => $nama !== '' ? $nama : '-',
            'alamat' => $alamat !== '' ? $alamat : '-',
            'sektor' => $sektor !== '' ? $sektor : 'Lainnya',
            'sub_sektor' => $this->cleanSubSektor($row['sub_sektor'] ?? null),
            'area' => $area,
            'kantor_id' => $kantorId,
            'status_mitra' => $bank,
            'pic' => $this->blankToNull($row['pic'] ?? null),
            'created_by' => $this->user->id,
            // Set explicitly, not left to the DB column default — see the
            // matching note in PoiController::store(): PoiObserver reads the
            // in-memory attribute, which is null (not 'aktif') until saved
            // unless it's assigned here.
            'status' => 'aktif',
        ]);
    }

    /**
     * Outlet is the only rejectable field, and only when blank — see class
     * docblock for why an unrecognized (non-blank) outlet is NOT rejected
     * for admin (auto-created in model() instead), and why it still IS
     * rejected for admin_final.
     */
    public function rules(): array
    {
        return [
            'outlet' => ['required', 'string', function ($attribute, $value, $fail) {
                $normalized = $this->normalize((string) $value);
                $kantorId = $this->kantorMap[$normalized] ?? null;

                if ($kantorId === null) {
                    if ($this->allowedKantorIds !== null) {
                        $fail("Outlet '{$value}' tidak ditemukan di kantor yang Anda kelola.");
                    }

                    // Unknown outlet, unrestricted (admin) user: not a
                    // failure — model() creates the kantor.
                    return;
                }

                if ($this->allowedKantorIds !== null && ! in_array($kantorId, $this->allowedKantorIds, true)) {
                    $fail("Outlet '{$value}' bukan kantor yang Anda kelola.");
                }
            }],
        ];
    }

    public function customValidationAttributes(): array
    {
        return [
            'outlet' => 'Outlet',
        ];
    }

    public function customValidationMessages(): array
    {
        return [
            'outlet.required' => 'Outlet wajib diisi.',
        ];
    }

    public function chunkSize(): int
    {
        return 500;
    }

    /**
     * @return array<string, self>
     */
    public function sheets(): array
    {
        return [$this->sheetName => $this];
    }

    public function importedCount(): int
    {
        return $this->importedCount;
    }

    /**
     * Runtime (non-validation) save failure — logged for diagnosability and
     * recorded so the controller can surface an accurate count instead of a
     * misleading one. importedCount() was already incremented optimistically
     * in model() before this row's save was attempted; walk it back since it
     * didn't actually persist.
     */
    public function onError(Throwable $e): void
    {
        $this->importedCount--;
        $this->errors[] = $e;

        Log::error('PoiImport: baris gagal disimpan karena error teknis (bukan validasi).', [
            'exception' => $e->getMessage(),
        ]);
    }

    /**
     * @return Throwable[]
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /** Case/whitespace-insensitive key for matching against canonical lists. */
    private function normalize(string $value): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/', ' ', $value)));
    }

    /**
     * Looks up $kantorMap first (covers both pre-existing kantor and ones
     * already created earlier in this same import run — a name repeated
     * across many rows only ever creates one Kantor). Only reached for
     * admin (rules() already rejects an unrecognized outlet for
     * admin_final, so model() never calls this in that case) — creating
     * kantor on the fly is intentionally an admin-only side effect.
     */
    private function resolveOrCreateKantorId(string $rawOutlet): int
    {
        $normalized = $this->normalize($rawOutlet);

        if (isset($this->kantorMap[$normalized])) {
            return $this->kantorMap[$normalized];
        }

        $kantor = Kantor::create([
            'kode' => $this->generateKode($rawOutlet),
            'nama' => $rawOutlet,
            'is_active' => true,
        ]);

        return $this->kantorMap[$normalized] = $kantor->id;
    }

    /**
     * kantor.kode has a unique constraint but no meaning anywhere else in
     * the app besides identifying the dashboard_summary "ALL" sentinel row
     * (Kantor::SENTINEL_ALL_KODE) — safe to derive from the name itself.
     */
    private function generateKode(string $nama): string
    {
        $base = Str::upper(Str::slug($nama, '_'));
        $base = $base !== '' ? Str::limit($base, 50, '') : 'KANTOR';

        $kode = $base;
        $suffix = 1;
        while (Kantor::where('kode', $kode)->exists()) {
            $kode = $base.'_'.(++$suffix);
        }

        return $kode;
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return $value === '' || $value === null ? null : (string) $value;
    }

    /**
     * Source files sometimes carry a literal "nan" in Sub Sektor — an
     * artifact of blank cells round-tripping through pandas/NaN-aware
     * tooling before being saved back to Excel/CSV, not a real value.
     * Treated the same as blank.
     */
    private function cleanSubSektor(mixed $value): ?string
    {
        $value = $this->blankToNull($value);

        return $value !== null && strtolower($value) === 'nan' ? null : $value;
    }
}
