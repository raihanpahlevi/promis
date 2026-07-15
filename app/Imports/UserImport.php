<?php

namespace App\Imports;

use App\Models\Kantor;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\PersistRelations;
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
 * Bulk import for User. Columns (heading row -> snake_case key, per
 * Maatwebsite's Str::slug formatter): NPP, Nama Lengkap, Unit / Jabatan,
 * Role Sistem, Kantor -> npp, nama_lengkap, unit_jabatan, role_sistem,
 * kantor.
 *
 * Product decision (2026-07-15, mirrors PoiImport's philosophy): **every
 * field is required EXCEPT Unit / Jabatan.** Unlike PoiImport, this is
 * deliberately stricter — NPP, Nama Lengkap, Role Sistem, and Kantor must
 * all be non-blank and resolve to something real, because:
 *   - npp is the login username (must be unique, never blank).
 *   - role_sistem drives RBAC — unlike POI's sektor/area, an unrecognized
 *     value here can NEVER fall back to free text or a safe default; it
 *     must resolve to exactly one of admin/admin_final/sales or the row is
 *     rejected. Matching is still case/whitespace-insensitive (normalize()).
 *   - kantor must resolve to real, existing Kantor row(s) (comma-separated
 *     for more than one) — rejected if any listed name doesn't match. This
 *     import does NOT auto-create kantor the way PoiImport does; by the
 *     time users are being imported, kantor master data should already be
 *     populated (e.g. from a prior POI import), so an unrecognized kantor
 *     here is more likely a typo than a genuinely new office.
 * Unit / Jabatan is the one exception: blank is allowed (unit_id stays
 * null), and a non-blank value that doesn't match an existing active unit
 * auto-creates a new Unit row (resolveOrCreateUnit()) instead of rejecting
 * the row — mirrors PoiImport's kantor auto-create, since real org-chart
 * titles vary a lot and shouldn't have to be pre-registered one by one.
 * Kantor/role matching is case-insensitive and whitespace-tolerant
 * (normalize()) so formatting differences in a hand-built file don't trip
 * either the fallback or the rejection.
 *
 * This module is admin-only (routes/user.php gates every route `role:admin`,
 * no admin_final carve-out like PoiImport has) so there is no per-user kantor
 * scoping here — any admin running this import may create users assigned to
 * any kantor.
 *
 * Implements PersistRelations (a marker interface, no methods) rather than
 * attaching the `kantor` pivot manually after save: ModelManager::singleFlush()
 * checks for this interface and, when present, routes the model through
 * CascadePersistManager::persist() instead of a plain saveOrFail(). That
 * cascade (a) wraps save + relation persistence in a single DB transaction
 * (excel config's default 'db' TransactionHandler) and (b) walks
 * $model->getRelations() and calls $relation->save($related) for each
 * BelongsToMany relation set on the model — i.e. setting
 * $user->setRelation('kantor', $kantorModels) in model() below is enough to
 * get the user row AND every user_kantor pivot row inserted atomically per
 * row, which is exactly the "one DB transaction per row, no partial user
 * with zero kantor attached" requirement from the brief.
 *
 * Deliberately NOT using WithBatchInserts, same reasoning as PoiImport: it
 * would bypass Eloquent (and, here, bypass PersistRelations entirely — mass
 * inserts never call singleFlush()). Plain ToModel means ModelManager's
 * internal per-row batchSize defaults to 1, so rows are validated and
 * persisted one at a time — WithChunkReading (chunkSize()) only affects how
 * the worksheet is paged off disk, not this one-row-at-a-time cadence.
 *
 * Row-order tracking (prepareForValidation()) plus an in-memory $seenNpp map
 * is what catches "duplicate NPP within the same uploaded file" — the DB
 * `unique` constraint alone can't do that since none of the duplicate rows
 * have been inserted yet when row 2 of the file is being validated.
 *
 * The real template has a "Petunjuk" instructions sheet alongside "Data
 * User" — WithMultipleSheets + $sheetName restricts processing to one sheet
 * so "Petunjuk" never gets misread as data rows. The controller resolves
 * $sheetName to the file's only sheet when it has just one (any name), and
 * falls back to the literal "Data User" for multi-sheet files like the
 * official template — see UserImportController::store().
 *
 * SkipsOnError (2026-07-15 fix, same reasoning as PoiImport): a runtime save
 * failure (DB constraint, dropped connection, etc.) is not a validation
 * failure — without SkipsOnError, ModelManager re-throws it, which used to
 * abort the whole WithChunkReading transaction and surface a misleading
 * "wrong sheet name" message with no log trail. onError() logs the real
 * exception and corrects importedCount() back down.
 */
class UserImport implements PersistRelations, SkipsEmptyRows, SkipsOnError, SkipsOnFailure, ToModel, WithChunkReading, WithHeadingRow, WithMultipleSheets, WithValidation
{
    use SkipsFailures;

    private const VALID_ROLES = [User::ROLE_ADMIN, User::ROLE_ADMIN_FINAL, User::ROLE_SALES];

    /** @var Collection<string, Kantor> normalize(kantor.nama) => Kantor model */
    private Collection $kantorModels;

    /** @var Collection<string, Unit> normalize(unit.nama, active only) => Unit model */
    private Collection $unitModels;

    /** @var array<string, string> normalize(value) => canonical VALID_ROLES value */
    private array $roleMap;

    /** @var array<string, int> npp => the first row index (in this file) it was seen at */
    private array $seenNpp = [];

    private int $currentRowIndex = 0;

    private int $importedCount = 0;

    /** @var Throwable[] */
    private array $errors = [];

    public function __construct(private readonly string $sheetName = 'Data User')
    {
        $this->kantorModels = Kantor::where('kode', '!=', Kantor::SENTINEL_ALL_KODE)->get()
            ->keyBy(fn ($k) => $this->normalize($k->nama));

        $this->unitModels = Unit::where('is_active', true)->get()
            ->keyBy(fn ($u) => $this->normalize($u->nama));

        $this->roleMap = collect(self::VALID_ROLES)->mapWithKeys(fn ($v) => [$this->normalize($v) => $v])->all();
    }

    /**
     * Optional WithValidation hook (Maatwebsite calls it via
     * method_exists() if present): gives us the absolute sheet row index
     * for the row about to be validated, which the `npp` rule closure below
     * uses to report "duplicate with row X".
     */
    public function prepareForValidation(array $data, int $index): array
    {
        $this->currentRowIndex = $index;

        return $data;
    }

    public function model(array $row): ?User
    {
        $npp = trim((string) $row['npp']);
        $role = $this->roleMap[$this->normalize((string) ($row['role_sistem'] ?? ''))] ?? null;

        // Should already be rejected by rules(), guard defensively anyway
        // rather than creating a user with a bad role or no kantor.
        if ($npp === '' || $role === null) {
            return null;
        }

        $kantorModels = collect($this->splitKantorNames($row['kantor'] ?? ''))
            ->map(fn ($name) => $this->kantorModels->get($this->normalize($name)))
            ->filter()
            ->values();

        if ($kantorModels->isEmpty()) {
            return null;
        }

        $unitRaw = trim((string) ($row['unit_jabatan'] ?? ''));
        $unit = $unitRaw !== '' ? $this->resolveOrCreateUnit($unitRaw) : null;

        $this->importedCount++;

        $user = new User([
            'npp' => $npp,
            'nama_lengkap' => trim((string) $row['nama_lengkap']),
            'unit_id' => $unit?->id,
            // Mandated "password awal = NPP" scheme, only safe because
            // force_password_change is always set true alongside it and
            // ForcePasswordChange middleware can't be bypassed.
            'password' => $npp,
            'role' => $role,
            'force_password_change' => true,
            'is_active' => true,
        ]);

        // Consumed by CascadePersistManager (see class docblock) to attach
        // the user_kantor pivot rows in the same transaction as the insert.
        $user->setRelation('kantor', $kantorModels);

        return $user;
    }

    public function rules(): array
    {
        return [
            // No 'string' type rule here on purpose: NPP is all-digits and
            // PhpSpreadsheet reads a numeric-looking cell (the real
            // template's own example row uses "12345") back as an int/float,
            // not a string — Laravel's `string` rule would then hard-reject
            // every numeric NPP. The closure below normalizes via (string)
            // cast regardless of the raw cell type.
            'npp' => ['required', 'max:50', function ($attribute, $value, $fail) {
                $npp = trim((string) $value);

                if ($npp === '') {
                    return;
                }

                if (isset($this->seenNpp[$npp])) {
                    $fail("NPP '{$npp}' duplikat dengan baris {$this->seenNpp[$npp]} pada file ini.");

                    return;
                }

                $this->seenNpp[$npp] = $this->currentRowIndex;

                if (User::where('npp', $npp)->exists()) {
                    $fail("NPP '{$npp}' sudah terdaftar di sistem.");
                }
            }],
            'nama_lengkap' => ['required', 'string', 'max:255'],
            // Unit / Jabatan is the one field allowed to be blank — see class
            // docblock. No rule here at all: a non-blank-but-unrecognized
            // value auto-creates a Unit in model(), it never fails validation.
            'role_sistem' => ['required', 'string', function ($attribute, $value, $fail) {
                if (! isset($this->roleMap[$this->normalize((string) $value)])) {
                    $fail("Role Sistem '{$value}' tidak dikenali. Harus salah satu: admin, admin_final, sales.");
                }
            }],
            'kantor' => ['required', 'string', function ($attribute, $value, $fail) {
                $names = $this->splitKantorNames($value);

                if (empty($names)) {
                    $fail('Kantor wajib diisi.');

                    return;
                }

                $missing = array_values(array_filter(
                    $names,
                    fn ($name) => ! $this->kantorModels->has($this->normalize($name))
                ));

                if (! empty($missing)) {
                    $fail('Kantor tidak ditemukan di master kantor: '.implode(', ', $missing).'.');
                }
            }],
        ];
    }

    public function customValidationAttributes(): array
    {
        return [
            'npp' => 'NPP',
            'nama_lengkap' => 'Nama Lengkap',
            'unit_jabatan' => 'Unit / Jabatan',
            'role_sistem' => 'Role Sistem',
            'kantor' => 'Kantor',
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
     * Runtime (non-validation) save failure — see class docblock. importedCount()
     * was already incremented optimistically in model() before this row's
     * save/cascade-persist was attempted; walk it back since it didn't persist.
     */
    public function onError(Throwable $e): void
    {
        $this->importedCount--;
        $this->errors[] = $e;

        Log::error('UserImport: baris gagal disimpan karena error teknis (bukan validasi).', [
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
     * Looks up $unitModels first (covers both pre-existing active units and
     * ones already created earlier in this same import run — a title
     * repeated across many rows only ever creates one Unit).
     */
    private function resolveOrCreateUnit(string $rawName): Unit
    {
        $normalized = $this->normalize($rawName);

        if ($this->unitModels->has($normalized)) {
            return $this->unitModels->get($normalized);
        }

        $unit = Unit::create(['nama' => trim($rawName), 'is_active' => true]);
        $this->unitModels->put($normalized, $unit);

        return $unit;
    }

    /**
     * @return string[]
     */
    private function splitKantorNames(mixed $value): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', (string) $value)),
            fn ($name) => $name !== ''
        ));
    }
}
