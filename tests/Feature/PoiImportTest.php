<?php

namespace Tests\Feature;

use App\Imports\PoiImport;
use App\Models\Kantor;
use App\Models\Kunjungan;
use App\Models\Poi;
use App\Models\User;
use Database\Factories\PoiFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\Concerns\RegistersPoiRoutes;
use Tests\TestCase;

class PoiImportTest extends TestCase
{
    use RefreshDatabase;
    use RegistersPoiRoutes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerPoiRoutes();

        Kantor::firstOrCreate(
            ['kode' => Kantor::SENTINEL_ALL_KODE],
            ['nama' => 'Seluruh Kantor', 'is_active' => false],
        );
    }

    /**
     * Builds a throwaway .xlsx fixture matching the real template's shape:
     * a "Data POI" sheet with the exact Nama/Alamat/Sektor/Sub Sektor/Area/
     * Outlet/Bank/PIC header row, plus decoy "Petunjuk" and "_lookup" sheets
     * (mirrors the real Template_Import_POI_PROMIS.xlsx layout) so the
     * WithMultipleSheets restriction to "Data POI" is actually exercised.
     *
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function buildFixture(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet();

        $petunjuk = $spreadsheet->getActiveSheet();
        $petunjuk->setTitle('Petunjuk');
        $petunjuk->setCellValue('A1', 'Ini bukan data, jangan diimport.');

        $dataPoi = $spreadsheet->createSheet();
        $dataPoi->setTitle('Data POI');
        $dataPoi->fromArray(
            ['Nama', 'Alamat', 'Sektor', 'Sub Sektor', 'Area', 'Outlet', 'Bank', 'PIC'],
            null,
            'A1'
        );
        $dataPoi->fromArray($rows, null, 'A2');

        $lookup = $spreadsheet->createSheet();
        $lookup->setTitle('_lookup');
        $lookup->setCellValue('A1', Poi::SEKTOR_OPTIONS[0]);
        $lookup->setCellValue('B1', Poi::STATUS_MITRA_OPTIONS[0]);

        $path = tempnam(sys_get_temp_dir(), 'poi_import_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /**
     * Same shape as buildFixture() plus a leading "ID" column — mirrors what
     * PoiExport actually produces, for the ID-based upsert re-import path.
     *
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function buildFixtureWithId(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet();

        $dataPoi = $spreadsheet->getActiveSheet();
        $dataPoi->setTitle('Data POI');
        $dataPoi->fromArray(
            ['ID', 'Nama', 'Alamat', 'Sektor', 'Sub Sektor', 'Area', 'Outlet', 'Bank', 'PIC'],
            null,
            'A1'
        );
        $dataPoi->fromArray($rows, null, 'A2');

        $path = tempnam(sys_get_temp_dir(), 'poi_import_id_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /**
     * A runtime save failure (DB constraint, dropped connection, etc.) is a
     * different failure mode than a validation rejection — onError() must
     * walk importedCount() back down (model() increments it optimistically
     * before the save is attempted) and record the exception, instead of
     * letting it propagate and abort the whole import (2026-07-15 fix).
     */
    public function test_on_error_reconciles_imported_count_and_records_the_exception(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $import = new PoiImport($admin);

        $import->model([
            'nama' => 'Toko A', 'alamat' => 'Jl. A', 'sektor' => 'Retail',
            'sub_sektor' => '', 'area' => '', 'outlet' => 'Kantor A', 'bank' => '', 'pic' => '',
        ]);
        $this->assertSame(1, $import->importedCount());

        $exception = new RuntimeException('Simulated DB failure');
        $import->onError($exception);

        $this->assertSame(0, $import->importedCount());
        $this->assertSame([$exception], $import->errors());
    }

    /**
     * Product decision (explicit, not an oversight): Outlet only rejects a
     * row when it's blank. sektor is free text (stored as-is, whatever the
     * file says); bank being a nonsense value falls back to the "not a BNI
     * partner yet" bucket instead of dropping the row — see PoiImport's
     * class docblock for why that particular fallback is the safe one.
     */
    public function test_admin_import_only_rejects_rows_with_a_blank_outlet(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $kantorA = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $kantorB = Kantor::create(['kode' => 'B', 'nama' => 'Kantor B']);

        $file = $this->buildFixture([
            ['Toko Valid A', 'Jl. A No. 1', Poi::SEKTOR_OPTIONS[0], 'CAFE', Poi::AREA_OPTIONS[0], 'Kantor A', Poi::STATUS_MITRA_OPTIONS[0], 'Branch Manager'],
            ['Toko Outlet Kosong', 'Jl. B No. 2', Poi::SEKTOR_OPTIONS[0], '', '', '', Poi::STATUS_MITRA_OPTIONS[0], ''],
            ['Toko Sektor Ngasal', 'Jl. C No. 3', 'Sektor Ngasal', '', '', 'Kantor A', Poi::STATUS_MITRA_OPTIONS[0], ''],
            ['Toko Bank Ngasal', 'Jl. D No. 4', Poi::SEKTOR_OPTIONS[0], '', '', 'Kantor A', 'Bank Ngasal', ''],
            ['Toko Valid B', 'Jl. E No. 5', Poi::SEKTOR_OPTIONS[1], '', '', 'Kantor B', Poi::STATUS_MITRA_OPTIONS[1], ''],
        ]);

        $response = $this->actingAs($admin)->post('/poi-import', ['file' => $file]);

        $response->assertRedirect(route('poi.import.create'));
        $summary = session('import_summary');

        $this->assertSame(4, $summary['imported']);
        $this->assertSame(1, $summary['rejected']);

        $reasons = collect($summary['errors'])->flatMap(fn ($e) => $e['errors'])->implode(' | ');
        $this->assertStringContainsString('Outlet wajib diisi', $reasons);

        $this->assertDatabaseHas('poi', ['nama_poi' => 'Toko Valid A', 'kantor_id' => $kantorA->id]);
        $this->assertDatabaseHas('poi', ['nama_poi' => 'Toko Valid B', 'kantor_id' => $kantorB->id]);
        $this->assertDatabaseMissing('poi', ['nama_poi' => 'Toko Outlet Kosong']);

        // Sektor is stored verbatim (free text); a nonsense bank value lands
        // with a safe fallback instead of being dropped.
        $this->assertDatabaseHas('poi', ['nama_poi' => 'Toko Sektor Ngasal', 'sektor' => 'Sektor Ngasal']);
        $this->assertDatabaseHas('poi', ['nama_poi' => 'Toko Bank Ngasal', 'status_mitra' => Poi::BELUM_BERMITRA_BNI]);

        // Went through Eloquent (PoiObserver fired), not a raw bulk insert.
        $this->assertDatabaseHas('dashboard_summary', ['kantor_id' => $kantorA->id, 'total_poi' => 3]);
        $this->assertDatabaseHas('dashboard_summary', ['kantor_id' => $kantorB->id, 'total_poi' => 1]);
    }

    /**
     * The real data has hundreds of kantor not pre-registered — an admin's
     * import must create them on the fly rather than rejecting the row, and
     * a name repeated across many rows must only create ONE Kantor (not one
     * per row).
     */
    public function test_admin_import_auto_creates_a_kantor_for_an_unrecognized_outlet(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);

        $file = $this->buildFixture([
            ['Toko Satu', 'Jl. A No. 1', Poi::SEKTOR_OPTIONS[0], '', '', 'Sukajadi Batam', Poi::STATUS_MITRA_OPTIONS[0], ''],
            ['Toko Dua', 'Jl. A No. 2', Poi::SEKTOR_OPTIONS[0], '', '', '  sukajadi batam  ', Poi::STATUS_MITRA_OPTIONS[0], ''],
        ]);

        $response = $this->actingAs($admin)->post('/poi-import', ['file' => $file]);

        $response->assertRedirect(route('poi.import.create'));
        $summary = session('import_summary');

        $this->assertSame(2, $summary['imported']);
        $this->assertSame(0, $summary['rejected']);

        $this->assertDatabaseHas('kantor', ['nama' => 'Sukajadi Batam']);
        $kantor = Kantor::where('nama', 'Sukajadi Batam')->first();
        $this->assertNotSame(Kantor::SENTINEL_ALL_KODE, $kantor->kode);
        $this->assertTrue($kantor->is_active);

        // Both rows (differently-cased/whitespaced) resolve to the SAME
        // newly-created kantor — not two separate ones.
        $this->assertSame(1, Kantor::where('nama', 'Sukajadi Batam')->count());
        $this->assertDatabaseHas('poi', ['nama_poi' => 'Toko Satu', 'kantor_id' => $kantor->id]);
        $this->assertDatabaseHas('poi', ['nama_poi' => 'Toko Dua', 'kantor_id' => $kantor->id]);
        $this->assertDatabaseHas('dashboard_summary', ['kantor_id' => $kantor->id, 'total_poi' => 2]);
    }

    /**
     * Auto-creating kantor from an import is an admin-only side effect —
     * admin_final stays strictly bounded to kantor they're already assigned
     * to, even for a name that simply doesn't exist anywhere yet.
     */
    public function test_admin_final_import_does_not_auto_create_kantor_and_rejects_unrecognized_outlet(): void
    {
        $adminFinal = User::factory()->adminFinal()->create(['force_password_change' => false]);
        $kantorMine = Kantor::create(['kode' => 'MINE', 'nama' => 'Kantor Mine']);
        $adminFinal->kantor()->attach($kantorMine->id);

        $file = $this->buildFixture([
            ['Toko Kantor Baru', 'Jl. A No. 1', Poi::SEKTOR_OPTIONS[0], '', '', 'Kantor Yang Belum Ada', Poi::STATUS_MITRA_OPTIONS[0], ''],
        ]);

        $response = $this->actingAs($adminFinal)->post('/poi-import', ['file' => $file]);

        $response->assertRedirect(route('poi.import.create'));
        $summary = session('import_summary');

        $this->assertSame(0, $summary['imported']);
        $this->assertSame(1, $summary['rejected']);
        $this->assertDatabaseMissing('kantor', ['nama' => 'Kantor Yang Belum Ada']);
        $this->assertDatabaseMissing('poi', ['nama_poi' => 'Toko Kantor Baru']);
    }

    public function test_admin_import_fills_blank_nama_and_alamat_with_a_placeholder(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);

        $file = $this->buildFixture([
            ['', '', Poi::SEKTOR_OPTIONS[0], '', '', 'Kantor A', Poi::STATUS_MITRA_OPTIONS[0], ''],
        ]);

        $response = $this->actingAs($admin)->post('/poi-import', ['file' => $file]);

        $response->assertRedirect(route('poi.import.create'));
        $this->assertSame(1, session('import_summary')['imported']);
        $this->assertDatabaseHas('poi', ['nama_poi' => '-', 'alamat' => '-', 'kantor_id' => $kantor->id]);
    }

    /**
     * A literal "nan" in Sub Sektor (common pandas/NaN round-trip artifact
     * in hand-built source files) is treated as blank, not stored verbatim.
     */
    public function test_admin_import_treats_a_literal_nan_sub_sektor_as_blank(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);

        $file = $this->buildFixture([
            ['Toko Nan', 'Jl. A No. 1', Poi::SEKTOR_OPTIONS[0], 'nan', '', 'Kantor A', Poi::STATUS_MITRA_OPTIONS[0], ''],
            ['Toko Nan Besar', 'Jl. A No. 2', Poi::SEKTOR_OPTIONS[0], 'NaN', '', 'Kantor A', Poi::STATUS_MITRA_OPTIONS[0], ''],
            ['Toko Sub Sektor Asli', 'Jl. A No. 3', Poi::SEKTOR_OPTIONS[0], 'CAFE', '', 'Kantor A', Poi::STATUS_MITRA_OPTIONS[0], ''],
        ]);

        $response = $this->actingAs($admin)->post('/poi-import', ['file' => $file]);

        $response->assertRedirect(route('poi.import.create'));
        $this->assertSame(3, session('import_summary')['imported']);
        $this->assertDatabaseHas('poi', ['nama_poi' => 'Toko Nan', 'sub_sektor' => null]);
        $this->assertDatabaseHas('poi', ['nama_poi' => 'Toko Nan Besar', 'sub_sektor' => null]);
        $this->assertDatabaseHas('poi', ['nama_poi' => 'Toko Sub Sektor Asli', 'sub_sektor' => 'CAFE']);
        $this->assertSame($kantor->id, Poi::where('nama_poi', 'Toko Nan')->value('kantor_id'));
    }

    /**
     * Outlet/Bank still get normalized matching (a real kantor/status has to
     * resolve regardless of formatting). Sektor is stored verbatim now, so a
     * differently-cased sektor is NOT coerced to the canonical casing.
     */
    public function test_admin_import_matches_outlet_and_bank_case_and_whitespace_insensitively(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);

        $file = $this->buildFixture([
            ['Toko Beda Huruf', 'Jl. A No. 1', strtolower(Poi::SEKTOR_OPTIONS[0]), '', '', '  kantor a  ', strtoupper(Poi::STATUS_MITRA_OPTIONS[0]), ''],
        ]);

        $response = $this->actingAs($admin)->post('/poi-import', ['file' => $file]);

        $response->assertRedirect(route('poi.import.create'));
        $this->assertSame(1, session('import_summary')['imported']);
        $this->assertDatabaseHas('poi', [
            'nama_poi' => 'Toko Beda Huruf',
            'kantor_id' => $kantor->id,
            'sektor' => strtolower(Poi::SEKTOR_OPTIONS[0]),
            'status_mitra' => Poi::STATUS_MITRA_OPTIONS[0],
        ]);
    }

    public function test_admin_final_import_rejects_rows_for_unowned_kantor_even_if_kantor_is_valid(): void
    {
        $adminFinal = User::factory()->adminFinal()->create(['force_password_change' => false]);
        $kantorMine = Kantor::create(['kode' => 'MINE', 'nama' => 'Kantor Mine']);
        $kantorOther = Kantor::create(['kode' => 'OTHER', 'nama' => 'Kantor Other']);
        $adminFinal->kantor()->attach($kantorMine->id);

        $file = $this->buildFixture([
            ['Toko Milik Sendiri', 'Jl. A No. 1', Poi::SEKTOR_OPTIONS[0], '', '', 'Kantor Mine', Poi::STATUS_MITRA_OPTIONS[0], ''],
            ['Toko Bukan Milik', 'Jl. B No. 2', Poi::SEKTOR_OPTIONS[0], '', '', 'Kantor Other', Poi::STATUS_MITRA_OPTIONS[0], ''],
        ]);

        $response = $this->actingAs($adminFinal)->post('/poi-import', ['file' => $file]);

        $response->assertRedirect(route('poi.import.create'));
        $summary = session('import_summary');

        $this->assertSame(1, $summary['imported']);
        $this->assertSame(1, $summary['rejected']);

        $reasons = collect($summary['errors'])->flatMap(fn ($e) => $e['errors'])->implode(' | ');
        $this->assertStringContainsString("bukan kantor yang Anda kelola", $reasons);

        $this->assertDatabaseHas('poi', ['nama_poi' => 'Toko Milik Sendiri', 'kantor_id' => $kantorMine->id]);
        $this->assertDatabaseMissing('poi', ['nama_poi' => 'Toko Bukan Milik']);
    }

    /**
     * A hand-built file with just one sheet (any tab name — not necessarily
     * "Data POI") must still import instead of crashing on a sheet-name
     * mismatch. The exact "Data POI" name is only needed to disambiguate
     * multi-sheet files (see buildFixture / PoiImportController::store).
     */
    public function test_single_sheet_file_imports_regardless_of_its_tab_name(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Sheet1');
        $sheet->fromArray(['Nama', 'Alamat', 'Sektor', 'Sub Sektor', 'Area', 'Outlet', 'Bank', 'PIC'], null, 'A1');
        $sheet->fromArray([['Toko Satu Sheet', 'Jl. A No. 1', Poi::SEKTOR_OPTIONS[0], '', '', 'Kantor A', Poi::STATUS_MITRA_OPTIONS[0], '']], null, 'A2');

        $path = tempnam(sys_get_temp_dir(), 'poi_import_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $file = new UploadedFile($path, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response = $this->actingAs($admin)->post('/poi-import', ['file' => $file]);

        $response->assertRedirect(route('poi.import.create'));
        $this->assertSame(1, session('import_summary')['imported']);
        $this->assertDatabaseHas('poi', ['nama_poi' => 'Toko Satu Sheet', 'kantor_id' => $kantor->id]);
    }

    public function test_sales_cannot_access_import(): void
    {
        $sales = User::factory()->create(['force_password_change' => false, 'role' => User::ROLE_SALES]);
        $kantor = Kantor::create(['kode' => 'X', 'nama' => 'Kantor X']);
        $sales->kantor()->attach($kantor->id);

        $this->actingAs($sales)->get('/poi-import')->assertForbidden();

        $file = $this->buildFixture([
            ['Toko X', 'Jl. X', Poi::SEKTOR_OPTIONS[0], '', '', 'Kantor X', Poi::STATUS_MITRA_OPTIONS[0], ''],
        ]);

        $this->actingAs($sales)->post('/poi-import', ['file' => $file])->assertForbidden();
        $this->assertDatabaseMissing('poi', ['nama_poi' => 'Toko X']);
    }

    public function test_template_download_serves_the_real_template_file(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);

        $response = $this->actingAs($admin)->get('/poi-import/template');

        $response->assertOk();
    }

    // ---------------- ID-based upsert (2026-07-16) ----------------

    /**
     * The core scenario: export POI, fill in the columns that came back
     * blank, re-import the same file. Must UPDATE the existing row (kunjungan
     * history stays attached, no duplicate), and blank cells must leave the
     * existing value alone rather than clearing it or applying the
     * insert-time placeholder.
     */
    public function test_import_with_id_updates_the_existing_poi_and_leaves_blank_cells_untouched(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $poi = PoiFactory::new()->create([
            'kantor_id' => $kantor->id,
            'nama_poi' => 'Toko Lama',
            'alamat' => 'Jl. Lama No. 1',
            'sektor' => 'Retail',
            'sub_sektor' => 'CAFE',
            'pic' => 'PIC Lama',
            'status_mitra' => Poi::STATUS_MITRA_OPTIONS[0],
        ]);
        $sales = User::factory()->create(['force_password_change' => false, 'role' => User::ROLE_SALES]);
        $kunjungan = Kunjungan::create([
            'poi_id' => $poi->id, 'sales_id' => $sales->id,
            'tanggal_kunjungan' => now()->toDateString(), 'hasil' => Kunjungan::HASIL_BERMINAT,
        ]);

        // Only Alamat and PIC are filled in; Nama/Sektor/Sub Sektor left
        // blank on purpose (simulating "cuma isi kolom yang kosong").
        $file = $this->buildFixtureWithId([
            [$poi->id, '', 'Jl. Baru No. 99', '', '', '', 'Kantor A', '', 'PIC Baru'],
        ]);

        $response = $this->actingAs($admin)->post('/poi-import', ['file' => $file]);

        $response->assertRedirect(route('poi.import.create'));
        $summary = session('import_summary');
        $this->assertSame(1, $summary['imported']);
        $this->assertSame(0, $summary['rejected']);

        // No duplicate — still exactly one POI row.
        $this->assertSame(1, Poi::count());

        $poi->refresh();
        $this->assertSame('Jl. Baru No. 99', $poi->alamat, 'filled cell must overwrite');
        $this->assertSame('PIC Baru', $poi->pic, 'filled cell must overwrite');
        $this->assertSame('Toko Lama', $poi->nama_poi, 'blank cell must leave the existing value alone');
        $this->assertSame('Retail', $poi->sektor, 'blank cell must leave the existing value alone');
        $this->assertSame('CAFE', $poi->sub_sektor, 'blank cell must leave the existing value alone');
        $this->assertSame(Poi::STATUS_MITRA_OPTIONS[0], $poi->status_mitra, 'blank Bank cell must not reset status_mitra');

        // Visit history stays attached to the same POI — nothing deleted.
        $this->assertDatabaseHas('kunjungan', ['id' => $kunjungan->id, 'poi_id' => $poi->id]);
    }

    public function test_import_with_id_can_reassign_kantor_via_outlet(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $kantorOld = Kantor::create(['kode' => 'OLD', 'nama' => 'Kantor Lama']);
        $kantorNew = Kantor::create(['kode' => 'NEW', 'nama' => 'Kantor Baru']);
        $poi = PoiFactory::new()->create(['kantor_id' => $kantorOld->id]);

        $file = $this->buildFixtureWithId([
            [$poi->id, '', '', '', '', '', 'Kantor Baru', '', ''],
        ]);

        $response = $this->actingAs($admin)->post('/poi-import', ['file' => $file]);

        $response->assertRedirect(route('poi.import.create'));
        $this->assertSame(1, session('import_summary')['imported']);

        $poi->refresh();
        $this->assertSame($kantorNew->id, $poi->kantor_id);
        $this->assertDatabaseHas('dashboard_summary', ['kantor_id' => $kantorOld->id, 'total_poi' => 0]);
        $this->assertDatabaseHas('dashboard_summary', ['kantor_id' => $kantorNew->id, 'total_poi' => 1]);
    }

    public function test_import_rejects_an_id_that_does_not_exist(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);

        $file = $this->buildFixtureWithId([
            [999999, 'Toko Hantu', '', '', '', '', 'Kantor A', '', ''],
        ]);

        $response = $this->actingAs($admin)->post('/poi-import', ['file' => $file]);

        $summary = session('import_summary');
        $this->assertSame(0, $summary['imported']);
        $this->assertSame(1, $summary['rejected']);
        $reasons = collect($summary['errors'])->flatMap(fn ($e) => $e['errors'])->implode(' | ');
        $this->assertStringContainsString('tidak ditemukan', $reasons);
        $this->assertDatabaseMissing('poi', ['nama_poi' => 'Toko Hantu']);
    }

    /**
     * admin_final can only update POI in kantor they're assigned to — an ID
     * pointing at a POI in another kantor must be rejected, not silently
     * applied (that would be a privilege-escalation path around the same
     * scoping every other write in this app enforces).
     */
    public function test_admin_final_cannot_update_a_poi_outside_their_kantor_via_id(): void
    {
        $adminFinal = User::factory()->adminFinal()->create(['force_password_change' => false]);
        $kantorMine = Kantor::create(['kode' => 'MINE', 'nama' => 'Kantor Mine']);
        $kantorOther = Kantor::create(['kode' => 'OTHER', 'nama' => 'Kantor Other']);
        $adminFinal->kantor()->attach($kantorMine->id);

        $poiOther = PoiFactory::new()->create(['kantor_id' => $kantorOther->id, 'nama_poi' => 'Toko Bukan Punyaku']);

        // Outlet points at a kantor admin_final DOES own (isolates the
        // assertion to the `id` rule's ownership check — the POI's current
        // kantor, Kantor Other, is what's actually unowned here) — if Outlet
        // also pointed at an unowned kantor, that rule would independently
        // reject the row too and the row would fail for two reasons instead
        // of the one this test means to verify.
        $file = $this->buildFixtureWithId([
            [$poiOther->id, 'Toko Diubah Paksa', '', '', '', '', 'Kantor Mine', '', ''],
        ]);

        $response = $this->actingAs($adminFinal)->post('/poi-import', ['file' => $file]);

        $summary = session('import_summary');
        $this->assertSame(0, $summary['imported']);
        $this->assertSame(1, $summary['rejected']);
        $reasons = collect($summary['errors'])->flatMap(fn ($e) => $e['errors'])->implode(' | ');
        $this->assertStringContainsString('bukan tanggung jawab Anda', $reasons);

        $poiOther->refresh();
        $this->assertSame('Toko Bukan Punyaku', $poiOther->nama_poi, 'unauthorized update must not apply');
    }

    /**
     * admin_final CAN update POI they do own via ID, same as their normal
     * manual-edit rights.
     */
    public function test_admin_final_can_update_a_poi_in_their_own_kantor_via_id(): void
    {
        $adminFinal = User::factory()->adminFinal()->create(['force_password_change' => false]);
        $kantorMine = Kantor::create(['kode' => 'MINE', 'nama' => 'Kantor Mine']);
        $adminFinal->kantor()->attach($kantorMine->id);

        $poi = PoiFactory::new()->create(['kantor_id' => $kantorMine->id, 'pic' => '']);

        $file = $this->buildFixtureWithId([
            [$poi->id, '', '', '', '', '', 'Kantor Mine', '', 'PIC Terisi'],
        ]);

        $response = $this->actingAs($adminFinal)->post('/poi-import', ['file' => $file]);

        $this->assertSame(1, session('import_summary')['imported']);
        $poi->refresh();
        $this->assertSame('PIC Terisi', $poi->pic);
    }

    /**
     * Re-uploading the exact same exported-and-filled file twice must not
     * create a second copy or touch the visit history on the second pass —
     * directly the scenario that prompted this feature.
     */
    public function test_reimporting_the_same_file_twice_does_not_duplicate_or_touch_kunjungan(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $poi = PoiFactory::new()->create(['kantor_id' => $kantor->id]);
        $sales = User::factory()->create(['force_password_change' => false, 'role' => User::ROLE_SALES]);
        $kunjungan = Kunjungan::create([
            'poi_id' => $poi->id, 'sales_id' => $sales->id,
            'tanggal_kunjungan' => now()->toDateString(), 'hasil' => Kunjungan::HASIL_BERMINAT,
        ]);

        $rows = [[$poi->id, '', '', '', '', '', 'Kantor A', '', 'PIC Sama']];

        $this->actingAs($admin)->post('/poi-import', ['file' => $this->buildFixtureWithId($rows)]);
        $this->actingAs($admin)->post('/poi-import', ['file' => $this->buildFixtureWithId($rows)]);

        $this->assertSame(1, Poi::count());
        $this->assertSame(1, Kunjungan::count());
        $this->assertDatabaseHas('kunjungan', ['id' => $kunjungan->id, 'poi_id' => $poi->id]);
        $this->assertDatabaseHas('dashboard_summary', ['kantor_id' => $kantor->id, 'total_poi' => 1]);
    }
}
