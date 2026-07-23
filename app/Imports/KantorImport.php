<?php

namespace App\Imports;

use App\Models\Kantor;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\SkipsFailures;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Throwable;

/**
 * Bulk create/update for Kantor ("Cabang") via Excel (heading row ->
 * snake_case key: ID, Kode, Cabang, Aktif, Area, Cabang-Cluster -> id, kode,
 * cabang, aktif, area, cabang_cluster). "Nama" is still accepted as a
 * fallback heading for the name column (kept, unlike PoiImport's old
 * headers, only because this whole feature is brand new this session — no
 * legacy file convention to break by keeping it).
 *
 * Two ways to target an existing row (2026-07-23, added for the
 * Area/Cabang-Cluster bulk-mapping use case): a non-blank ID updates that
 * Kantor directly, same as before. A row with NO id but a Cabang/Nama value
 * that matches an existing kantor's `nama` exactly is ALSO treated as an
 * update (not an insert) — this lets a plain "Cabang, Cabang-Cluster, Area"
 * mapping file (no ID column at all) be uploaded as-is to bulk-set the
 * hierarchy on already-existing kantor, without first exporting to get IDs.
 * Only when neither an id nor a matching name is found does a row insert a
 * new Kantor.
 *
 * Kode/Nama uniqueness is NOT re-validated here as a separate rule (unlike
 * PoiImport's outlet/id checks) — `kantor.kode`/`kantor.nama` already carry a
 * DB-level unique constraint, so a collision surfaces as a genuine save
 * failure through onError() same as any other technical error, rather than
 * duplicating that check in rules() (which would need per-row access to the
 * OTHER fields to build a correct Rule::unique()->ignore($id), more
 * machinery than this small, low-volume import (kantor count is "tens", per
 * LaporanController's docblock) is worth).
 *
 * The sentinel "ALL" kantor (Kantor::SENTINEL_ALL_KODE) is never a valid
 * target: KantorExport never emits it, and rules() explicitly rejects any ID
 * that resolves to it, so a hand-edited file can't corrupt the
 * dashboard_summary global-aggregate row that sentinel exists for. The
 * name-match fallback can't accidentally hit it either — its nama
 * ("Seluruh Kantor") isn't something a real Cabang mapping file would name a
 * row, but even if it did, is_active/kode/area on it would just get
 * overwritten harmlessly (not a security boundary, unlike the id path — this
 * sentinel isn't secret, just internal bookkeeping).
 */
class KantorImport implements SkipsEmptyRows, SkipsOnError, SkipsOnFailure, ToModel, WithHeadingRow, WithValidation
{
    use SkipsFailures;

    /** @var array<int, Kantor|false> memoized Kantor::find() by id — shared between rules() and model(), false = confirmed not found */
    private array $kantorCache = [];

    private int $importedCount = 0;

    /** @var Throwable[] */
    private array $errors = [];

    public function model(array $row): ?Kantor
    {
        $idRaw = trim((string) ($row['id'] ?? ''));
        $kode = trim((string) ($row['kode'] ?? ''));
        $nama = trim((string) ($row['cabang'] ?? $row['nama'] ?? ''));
        $area = $this->blankToNull($row['area'] ?? null);
        $cabangCluster = $this->blankToNull($row['cabang_cluster'] ?? null);
        $aktif = $this->parseAktif($row['aktif'] ?? null);

        $this->importedCount++;

        $kantor = null;

        if ($idRaw !== '') {
            $kantor = $this->findKantor((int) $idRaw);

            // Defensive guard only (rules() already validated this id exists
            // and isn't the sentinel) — e.g. a theoretical delete between
            // validation and save.
            if ($kantor === null) {
                $this->importedCount--;

                return null;
            }
        } elseif ($nama !== '') {
            // No ID given — fall back to an exact Nama match against an
            // existing kantor instead of always inserting (see class
            // docblock). `nama` is unique in the DB, so this is unambiguous.
            $kantor = Kantor::where('nama', $nama)->first();
        }

        if ($kantor !== null) {
            // Blank cell on an update row means "leave this field alone" —
            // same semantics as PoiImport's ID-based upsert, so filling in
            // just Area/Cabang-Cluster (say) doesn't accidentally blank Kode.
            if ($kode !== '') {
                $kantor->kode = $kode;
            }
            if ($nama !== '') {
                $kantor->nama = $nama;
            }
            if ($area !== null) {
                $kantor->area = $area;
            }
            if ($cabangCluster !== null) {
                $kantor->cabang_cluster = $cabangCluster;
            }
            if ($aktif !== null) {
                $kantor->is_active = $aktif;
            }

            return $kantor;
        }

        return new Kantor([
            'kode' => $kode,
            'nama' => $nama,
            'area' => $area,
            'cabang_cluster' => $cabangCluster,
            'is_active' => $aktif ?? true,
        ]);
    }

    public function rules(): array
    {
        return [
            'id' => ['nullable', 'integer', function ($attribute, $value, $fail) {
                if ($value === null || $value === '') {
                    return;
                }

                $kantor = $this->findKantor((int) $value);

                if ($kantor === null) {
                    $fail("ID {$value} tidak ditemukan di data kantor — kemungkinan sudah dihapus.");

                    return;
                }

                if ($kantor->kode === Kantor::SENTINEL_ALL_KODE) {
                    $fail("ID {$value} adalah baris sentinel sistem dan tidak bisa diubah.");
                }
            }],
        ];
    }

    public function customValidationAttributes(): array
    {
        return ['id' => 'ID'];
    }

    /**
     * Runtime (non-validation) save failure — most commonly a Kode/Nama that
     * collides with a DIFFERENT existing kantor (the DB unique constraint,
     * not a validation rule here — see class docblock). Logged for
     * diagnosability and recorded so the controller can surface an accurate
     * count instead of a misleading one; importedCount() was already
     * incremented optimistically in model() before this row's save was
     * attempted, so it's walked back down here.
     */
    public function onError(Throwable $e): void
    {
        $this->importedCount--;
        $this->errors[] = $e;

        Log::error('KantorImport: baris gagal disimpan karena error teknis (bukan validasi).', [
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

    public function importedCount(): int
    {
        return $this->importedCount;
    }

    private function findKantor(int $id): ?Kantor
    {
        if (! array_key_exists($id, $this->kantorCache)) {
            $this->kantorCache[$id] = Kantor::find($id) ?? false;
        }

        return $this->kantorCache[$id] ?: null;
    }

    /** Blank cell -> null ("leave alone" on an update row); anything else -> Ya/Aktif/1/true (case-insensitive) is active, everything else inactive. */
    private function parseAktif(mixed $value): ?bool
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        return in_array(mb_strtoupper($value), ['YA', 'AKTIF', '1', 'TRUE'], true);
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return $value === '' || $value === null ? null : (string) $value;
    }
}
