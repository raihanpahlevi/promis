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
 * Kategori, Sub Kategori, Ring Area, Cabang, Bank, PIC -> nama, alamat,
 * kategori, sub_kategori, ring_area, cabang, bank, pic. An optional leading
 * ID column (only present in files produced by PoiExport) switches a row
 * from insert to update — see "ID-based upsert" below.
 *
 * Header rename (2026-07-23, final — replaces the old Sektor/Sub Sektor/
 * Area/Outlet naming entirely, no backward-compat alias kept): Sektor ->
 * Kategori, Sub Sektor -> Sub Kategori, the ring-distance column (Area) ->
 * Ring Area, Outlet -> Cabang. The internal DB columns/attribute names are
 * unchanged (poi.sektor, poi.sub_sektor, poi.area, kantor.nama via
 * kantor_id) — only the recognized import heading text and this class's
 * local variable names follow the new terminology; renaming the DB schema
 * itself was explicitly out of scope for this change.
 *
 * Two additional trailing columns — Cabang-Cluster and Area (a broader
 * region, NOT the same thing as the ring-distance "Ring Area" above) — are
 * NOT stored on the POI itself (see applyCabangClusterArea()): every POI row
 * for the same Cabang would otherwise repeat the same two values, which is
 * exactly the "two sources of truth" shape that lets them silently drift.
 * Instead, a non-blank Cabang-Cluster/Area on a row stamps straight onto the
 * resolved Cabang (kantor.cabang_cluster / kantor.area) — same "blank means
 * leave alone" rule as every other field here — and a POI always displays
 * the hierarchy by reading through its Cabang (PoiController@show), never
 * from its own row. This also means a plain POI import IS how the Cabang ->
 * Cabang-Cluster -> Area mapping gets set in the first place (2026-07-23,
 * folded in here after the two-step "import Kelola Cabang mapping first,
 * then POI" workflow proved to be one step too many in practice) — Kelola
 * Kantor's own export/import still works too, for fixing the hierarchy
 * without re-uploading the whole POI file.
 *
 * Deliberately lenient by design (explicit product decision, 2026-07-14):
 * **Cabang is the only field that can reject a row, and only when it's
 * blank.** A non-blank Cabang that doesn't match any existing kantor is NOT
 * a rejection — the real data has hundreds of kantor not pre-registered in
 * the system yet, and requiring someone to manually create each one first
 * would defeat the point of a bulk import. Instead, an admin's import
 * auto-creates the missing Kantor row (resolveOrCreateKantorId()) so
 * kantor_id stays a real, valid foreign key — never falls back to storing
 * the Cabang name as free text, which would break every kantor-scoped
 * filter/dashboard/RBAC check in the app. admin_final is the exception: they
 * stay strictly bounded to kantor they're already assigned to (user_kantor)
 * — an unrecognized name for them is just wrong/unowned, never "a new
 * kantor to add", since introducing new kantor into the system is
 * effectively an admin-level action. Every other field has a safe fallback
 * instead of rejecting the row:
 *   - nama/alamat blank -> '-' placeholder (never blank in the UI/search).
 *   - kategori / ring_area -> stored as-is into sektor/area, whatever the
 *     file says (both are plain VARCHAR columns, not restricted to
 *     Poi::SEKTOR_OPTIONS/AREA_OPTIONS — that curated list is only enforced
 *     on the manual create/edit form's dropdown; import data is internal and
 *     doesn't need to match it). Only truly blank kategori falls back to
 *     'Lainnya' since the column is NOT NULL; blank ring_area stays null
 *     (already optional everywhere it's used). Exception: ring_area DOES get
 *     one narrow normalization (normalizeArea()) — a cell that's recognizably
 *     "Ring 1"/"ring2"/"RING  3" (case/whitespace-insensitive) is
 *     canonicalized to the exact Poi::AREA_OPTIONS string ("Ring 1", etc.),
 *     since the dashboard's ring breakdown filters on an exact string match
 *     — a near-miss wouldn't count. Anything that doesn't look like "Ring N"
 *     is left untouched, same lenient free-text handling as kategori.
 *   - bank not one of the 3 canonical status_mitra values -> falls back to
 *     Poi::BELUM_BERMITRA_BNI, NOT any other bucket: that's the only default
 *     that keeps the POI prospectable (visible to sales, countable in
 *     dashboard totals) instead of silently vanishing from every stat that
 *     hard-compares status_mitra (Dashboard's BNI/Non split, the "belum
 *     bermitra" pool KunjunganController offers sales to visit, the status
 *     badge). status_mitra stays a real ENUM (see migration) — unlike
 *     kategori/ring_area it's hard-compared all over the app, so it can't
 *     become free text without breaking that math; "not a recognized BNI
 *     value" is just treated as "not a BNI partner yet", which is the
 *     correct bucket.
 * Cabang/bank matching is case-insensitive and tolerant of extra whitespace
 * (normalize()), so formatting differences in a hand-built source file don't
 * trip either the fallback or the Cabang rejection.
 *
 * sub_kategori additionally treats a literal "nan" as blank (cleanSubSektor())
 * — a common artifact of blank cells round-tripping through pandas/NaN-aware
 * tooling before landing back in Excel/CSV.
 *
 * ID-based upsert (2026-07-16): the real workflow is "export POI to Excel,
 * fill in the columns that came back blank, import the same file again" —
 * without a way to recognize "this row already exists", every re-import
 * would insert a brand-new duplicate POI instead of completing the one
 * that's already there (this exact failure mode caused a real 11.5k-row
 * duplicate incident before ID-based matching existed). PoiExport's first
 * column is "ID" (the poi.id primary key); when a row's ID cell is
 * non-blank, model() fetches and updates that existing Poi instead of
 * creating a new one — Eloquent's save() does an UPDATE automatically for an
 * already-`exists`-true model, so this still goes through PoiObserver just
 * like every other write here.
 *
 * Update semantics are deliberately NOT the same as insert's "safe default"
 * fallbacks: a blank cell on an update row means "leave this field alone"
 * (that's the entire point — filling in gaps without clobbering whatever's
 * already correct), never "clear it" and never the insert-time placeholder
 * ('-' / 'Lainnya' / Poi::BELUM_BERMITRA_BNI). Only Cabang stays
 * unconditionally required and always drives kantor_id (on both insert and
 * update) — an update row can reassign a POI's kantor by changing Cabang,
 * same as PoiController::update() already allows via the manual edit form.
 * An unrecognized (non-blank) Bank value on an update row is also just
 * ignored (kept as-is) rather than falling back to Poi::BELUM_BERMITRA_BNI —
 * that fallback exists to keep a brand-new row prospectable, not to risk
 * silently downgrading an already-partnered POI's status_mitra from a typo
 * in the reimport file.
 *
 * rules()'s `id` check (existence + admin_final kantor-ownership of the
 * row's *current* kantor_id) reuses the same Poi instance model() goes on to
 * update (see $poiCache) — one fetch per ID-bearing row, not two.
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

    /** @var array<int, array{area: ?string, cabang_cluster: ?string}> kantor.id => its current area/cabang_cluster, kept in sync as this import writes to them (avoids re-querying per POI row — see applyCabangClusterArea()) */
    private array $kantorAreaClusterCache;

    /** @var array<string, string> normalize(value) => canonical Poi::STATUS_MITRA_OPTIONS value */
    private array $statusMitraMap;

    /** @var int[]|null kantor ids the acting user may import into; null = unrestricted (admin) */
    private ?array $allowedKantorIds;

    /** @var array<int, Poi|false> memoized Poi::find() by id — shared between rules()'s id check and model()'s update, false = confirmed not found */
    private array $poiCache = [];

    private int $importedCount = 0;

    /** @var Throwable[] */
    private array $errors = [];

    public function __construct(private readonly User $user, private readonly string $sheetName = 'Data POI')
    {
        $this->kantorMap = [];
        $this->kantorAreaClusterCache = [];
        foreach (Kantor::where('kode', '!=', Kantor::SENTINEL_ALL_KODE)->get(['id', 'nama', 'area', 'cabang_cluster']) as $kantor) {
            $this->kantorMap[$this->normalize($kantor->nama)] = $kantor->id;
            $this->kantorAreaClusterCache[$kantor->id] = ['area' => $kantor->area, 'cabang_cluster' => $kantor->cabang_cluster];
        }

        $this->statusMitraMap = collect(Poi::STATUS_MITRA_OPTIONS)->mapWithKeys(fn ($v) => [$this->normalize($v) => $v])->all();

        $this->allowedKantorIds = $user->isAdmin()
            ? null
            : $user->kantor()->pluck('kantor.id')->all();
    }

    public function model(array $row): ?Poi
    {
        $cabangRaw = trim((string) ($row['cabang'] ?? ''));

        // Blank Cabang is the only thing rules() rejects, so this only ever
        // returns null here as a defensive guard, not the normal path.
        if ($cabangRaw === '') {
            return null;
        }

        $kantorId = $this->resolveOrCreateKantorId($cabangRaw);
        $this->applyCabangClusterArea($kantorId, $row['cabang_cluster'] ?? null, $row['area'] ?? null);
        $idRaw = trim((string) ($row['id'] ?? ''));

        if ($idRaw !== '') {
            return $this->buildUpdateModel((int) $idRaw, $row, $kantorId);
        }

        $nama = trim((string) ($row['nama'] ?? ''));
        $alamat = trim((string) ($row['alamat'] ?? ''));
        $kategori = trim((string) ($row['kategori'] ?? ''));
        $ringArea = $this->normalizeArea($row['ring_area'] ?? null);
        $bank = $this->statusMitraMap[$this->normalize((string) ($row['bank'] ?? ''))] ?? Poi::BELUM_BERMITRA_BNI;

        $this->importedCount++;

        return new Poi([
            'nama_poi' => $nama !== '' ? $nama : '-',
            'alamat' => $alamat !== '' ? $alamat : '-',
            'sektor' => $kategori !== '' ? $kategori : 'Lainnya',
            'sub_sektor' => $this->cleanSubSektor($row['sub_kategori'] ?? null),
            'area' => $ringArea,
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
     * Update path — see class docblock's "ID-based upsert" section for the
     * blank-means-leave-alone semantics. $id is already known valid (exists,
     * and owned by this user if admin_final) from rules(); findPoiForUpdate()
     * reuses that same fetch instead of querying again.
     */
    private function buildUpdateModel(int $id, array $row, int $kantorId): ?Poi
    {
        $poi = $this->findPoiForUpdate($id);

        // Defensive guard only (rules() already validated this id) — e.g. a
        // theoretical delete between validation and save.
        if ($poi === null) {
            return null;
        }

        $nama = trim((string) ($row['nama'] ?? ''));
        $alamat = trim((string) ($row['alamat'] ?? ''));
        $kategori = trim((string) ($row['kategori'] ?? ''));
        $subKategori = $this->cleanSubSektor($row['sub_kategori'] ?? null);
        $ringArea = $this->normalizeArea($row['ring_area'] ?? null);
        $bank = $this->statusMitraMap[$this->normalize((string) ($row['bank'] ?? ''))] ?? null;
        $pic = $this->blankToNull($row['pic'] ?? null);

        // Cabang is the only always-required field, so kantor_id always applies.
        $poi->kantor_id = $kantorId;
        if ($nama !== '') {
            $poi->nama_poi = $nama;
        }
        if ($alamat !== '') {
            $poi->alamat = $alamat;
        }
        if ($kategori !== '') {
            $poi->sektor = $kategori;
        }
        if ($subKategori !== null) {
            $poi->sub_sektor = $subKategori;
        }
        if ($ringArea !== null) {
            $poi->area = $ringArea;
        }
        if ($bank !== null) {
            $poi->status_mitra = $bank;
        }
        if ($pic !== null) {
            $poi->pic = $pic;
        }

        $this->importedCount++;

        return $poi;
    }

    /**
     * Cabang is the only rejectable field, and only when blank — see class
     * docblock for why an unrecognized (non-blank) Cabang is NOT rejected
     * for admin (auto-created in model() instead), and why it still IS
     * rejected for admin_final.
     */
    public function rules(): array
    {
        return [
            'cabang' => ['required', 'string', function ($attribute, $value, $fail) {
                $normalized = $this->normalize((string) $value);
                $kantorId = $this->kantorMap[$normalized] ?? null;

                if ($kantorId === null) {
                    if ($this->allowedKantorIds !== null) {
                        $fail("Cabang '{$value}' tidak ditemukan di kantor yang Anda kelola.");
                    }

                    // Unknown Cabang, unrestricted (admin) user: not a
                    // failure — model() creates the kantor.
                    return;
                }

                if ($this->allowedKantorIds !== null && ! in_array($kantorId, $this->allowedKantorIds, true)) {
                    $fail("Cabang '{$value}' bukan kantor yang Anda kelola.");
                }
            }],
            'id' => ['nullable', 'integer', function ($attribute, $value, $fail) {
                if ($value === null || $value === '') {
                    return;
                }

                $poi = $this->findPoiForUpdate((int) $value);

                if ($poi === null) {
                    $fail("ID {$value} tidak ditemukan di data POI — kemungkinan sudah dihapus.");

                    return;
                }

                if ($this->allowedKantorIds !== null && ! in_array($poi->kantor_id, $this->allowedKantorIds, true)) {
                    $fail("ID {$value} adalah POI di kantor yang bukan tanggung jawab Anda.");
                }
            }],
        ];
    }

    public function customValidationAttributes(): array
    {
        return [
            'cabang' => 'Cabang',
            'id' => 'ID',
        ];
    }

    public function customValidationMessages(): array
    {
        return [
            'cabang.required' => 'Cabang wajib diisi.',
            'id.integer' => 'ID harus berupa angka.',
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

    /**
     * Memoized so rules()'s existence/ownership check and model()'s update
     * both resolve to the exact same fetch for a given row (see $poiCache).
     */
    private function findPoiForUpdate(int $id): ?Poi
    {
        if (! array_key_exists($id, $this->poiCache)) {
            $this->poiCache[$id] = Poi::find($id) ?? false;
        }

        return $this->poiCache[$id] ?: null;
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
     * admin (rules() already rejects an unrecognized Cabang for
     * admin_final, so model() never calls this in that case) — creating
     * kantor on the fly is intentionally an admin-only side effect.
     */
    private function resolveOrCreateKantorId(string $rawCabang): int
    {
        $normalized = $this->normalize($rawCabang);

        if (isset($this->kantorMap[$normalized])) {
            return $this->kantorMap[$normalized];
        }

        $kantor = Kantor::create([
            'kode' => $this->generateKode($rawCabang),
            'nama' => $rawCabang,
            'is_active' => true,
        ]);

        $this->kantorAreaClusterCache[$kantor->id] = ['area' => null, 'cabang_cluster' => null];

        return $this->kantorMap[$normalized] = $kantor->id;
    }

    /**
     * Stamps a POI row's Cabang-Cluster/Area columns onto the resolved
     * Cabang itself (2026-07-23) — the real source file carries all three
     * (Cabang, Cabang-Cluster, Area) on every row, so requiring a separate
     * Kelola Cabang import first to set the hierarchy was one step too many
     * in practice; this folds it into the same POI import instead. Same
     * "blank means leave alone" semantics as everything else in this class —
     * a blank cell never clears an existing value.
     *
     * Only issues an UPDATE when the incoming value actually differs from
     * $kantorAreaClusterCache (kept in sync here), not once per POI row —
     * many rows share the same Cabang, and a real file is ~184k POI rows
     * against only ~139 Cabang, so a naive per-row write would be ~1300x more
     * DB writes than necessary for data that's the same on every row.
     */
    private function applyCabangClusterArea(int $kantorId, mixed $rawCabangCluster, mixed $rawArea): void
    {
        $cabangCluster = $this->blankToNull($rawCabangCluster);
        $area = $this->blankToNull($rawArea);

        if ($cabangCluster === null && $area === null) {
            return;
        }

        $current = $this->kantorAreaClusterCache[$kantorId];
        $updates = [];

        if ($cabangCluster !== null && $cabangCluster !== $current['cabang_cluster']) {
            $updates['cabang_cluster'] = $cabangCluster;
        }
        if ($area !== null && $area !== $current['area']) {
            $updates['area'] = $area;
        }

        if ($updates === []) {
            return;
        }

        Kantor::whereKey($kantorId)->update($updates);
        $this->kantorAreaClusterCache[$kantorId] = array_merge($current, $updates);
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

    /**
     * Canonicalizes "Ring 1"/"ring2"/"RING  3" shorthand (case/whitespace
     * insensitive, jarak suffix optional) to the exact Poi::AREA_OPTIONS
     * string the dashboard's ring breakdown filters on — see class docblock.
     * The regex only matches a bare "Ring" immediately followed by 1-4 and a
     * word boundary, so "Ring 10" or a value that merely contains "ring"
     * elsewhere doesn't false-positive; anything that doesn't match is
     * returned trimmed but otherwise untouched (free text, same as kategori).
     *
     * "Ring 0" is deliberately NOT a 5th bucket (there's no such ring in
     * Poi::AREA_OPTIONS or the dashboard breakdown) — product decision
     * (2026-07-22): treat it the same as a blank cell (null), not as literal
     * free text, since "Ring 0" is how the source data spells "no ring
     * assigned yet", not a genuine 5th distance band.
     */
    private function normalizeArea(mixed $value): ?string
    {
        $trimmed = $this->blankToNull($value);

        if ($trimmed === null) {
            return null;
        }

        $normalized = $this->normalize($trimmed);

        if (preg_match('/RING\s*0\b/', $normalized)) {
            return null;
        }

        if (preg_match('/RING\s*([1-4])\b/', $normalized, $m)) {
            return Poi::AREA_OPTIONS[((int) $m[1]) - 1];
        }

        return $trimmed;
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return $value === '' || $value === null ? null : (string) $value;
    }

    /**
     * Source files sometimes carry a literal "nan" in Sub Kategori — an
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
