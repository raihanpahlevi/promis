<?php

namespace Tests\Feature;

use App\Exports\KantorExport;
use App\Models\Kantor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\RegistersKantorRoutes;
use Tests\TestCase;

class KantorTest extends TestCase
{
    use RefreshDatabase;
    use RegistersKantorRoutes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerKantorRoutes();

        Kantor::firstOrCreate(
            ['kode' => Kantor::SENTINEL_ALL_KODE],
            ['nama' => 'Seluruh Kantor', 'is_active' => false],
        );
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function buildImportFixture(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['ID', 'Kode', 'Nama', 'Aktif'], null, 'A1');
        $sheet->fromArray($rows, null, 'A2');

        $path = tempnam(sys_get_temp_dir(), 'kantor_import_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    // ---------------- Role gating ----------------

    public function test_admin_final_gets_403_on_every_kantor_route(): void
    {
        $adminFinal = User::factory()->adminFinal()->create(['force_password_change' => false]);
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);

        $this->actingAs($adminFinal)->get('/kantor')->assertForbidden();
        $this->actingAs($adminFinal)->post('/kantor', [])->assertForbidden();
        $this->actingAs($adminFinal)->put("/kantor/{$kantor->id}", [])->assertForbidden();
        $this->actingAs($adminFinal)->post("/kantor/{$kantor->id}/toggle-active")->assertForbidden();
        $this->actingAs($adminFinal)->get('/kantor-import')->assertForbidden();
        $this->actingAs($adminFinal)->post('/kantor-import', [])->assertForbidden();
        $this->actingAs($adminFinal)->get('/export/kantor/download')->assertForbidden();
    }

    public function test_sales_gets_403_on_every_kantor_route(): void
    {
        $sales = User::factory()->create(['force_password_change' => false, 'role' => User::ROLE_SALES]);
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $sales->kantor()->attach($kantor->id);

        $this->actingAs($sales)->get('/kantor')->assertForbidden();
        $this->actingAs($sales)->post('/kantor', [])->assertForbidden();
        $this->actingAs($sales)->put("/kantor/{$kantor->id}", [])->assertForbidden();
    }

    // ---------------- index() ----------------

    public function test_index_lists_kantor_and_excludes_the_sentinel_row(): void
    {
        Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $admin = User::factory()->admin()->create(['force_password_change' => false]);

        $response = $this->actingAs($admin)->get('/kantor');

        $response->assertOk();
        $names = $response->viewData('kantorList')->pluck('nama');
        $this->assertTrue($names->contains('Kantor A'));
        $this->assertFalse($names->contains('Seluruh Kantor'));
    }

    // ---------------- store() / update() (single-row form) ----------------

    public function test_admin_can_add_a_kantor(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);

        $response = $this->actingAs($admin)->post('/kantor', ['kode' => 'JKT01', 'nama' => 'Kantor Jakarta']);

        $response->assertRedirect();
        $this->assertDatabaseHas('kantor', ['kode' => 'JKT01', 'nama' => 'Kantor Jakarta', 'is_active' => true]);
    }

    public function test_admin_can_set_area_and_cabang_cluster_on_add_and_update(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);

        $this->actingAs($admin)->post('/kantor', [
            'kode' => 'JKT01', 'nama' => 'Kantor Jakarta', 'area' => 'Area Jakarta', 'cabang_cluster' => 'Cluster Jakarta',
        ])->assertRedirect();

        $kantor = Kantor::where('kode', 'JKT01')->firstOrFail();
        $this->assertSame('Area Jakarta', $kantor->area);
        $this->assertSame('Cluster Jakarta', $kantor->cabang_cluster);

        $this->actingAs($admin)->put("/kantor/{$kantor->id}", [
            'kode' => 'JKT01', 'nama' => 'Kantor Jakarta', 'area' => 'Area Jakarta Baru', 'cabang_cluster' => 'Cluster Baru',
        ])->assertRedirect();

        $kantor->refresh();
        $this->assertSame('Area Jakarta Baru', $kantor->area);
        $this->assertSame('Cluster Baru', $kantor->cabang_cluster);
    }

    public function test_store_rejects_a_duplicate_kode_or_nama(): void
    {
        Kantor::create(['kode' => 'JKT01', 'nama' => 'Kantor Jakarta']);
        $admin = User::factory()->admin()->create(['force_password_change' => false]);

        $this->actingAs($admin)->post('/kantor', ['kode' => 'JKT01', 'nama' => 'Nama Lain'])
            ->assertSessionHasErrors('kode');
        $this->actingAs($admin)->post('/kantor', ['kode' => 'BEDA', 'nama' => 'Kantor Jakarta'])
            ->assertSessionHasErrors('nama');
    }

    /**
     * The actual motivating use case: fixing a kantor's name in place, not
     * creating a new one — see KantorExport's docblock for why this is the
     * safe alternative to hand-editing an Outlet name in a POI import.
     */
    public function test_admin_can_rename_a_kantor_in_place(): void
    {
        $kantor = Kantor::create(['kode' => 'JKT01', 'nama' => 'Kanto Jakarta']); // typo, on purpose
        $admin = User::factory()->admin()->create(['force_password_change' => false]);

        $response = $this->actingAs($admin)->put("/kantor/{$kantor->id}", ['kode' => 'JKT01', 'nama' => 'Kantor Jakarta']);

        $response->assertRedirect();
        $this->assertSame('Kantor Jakarta', $kantor->fresh()->nama);
        $this->assertSame(1, Kantor::where('nama', 'Kantor Jakarta')->count());
    }

    public function test_update_rejects_a_kode_or_nama_already_used_by_a_different_kantor(): void
    {
        Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $kantorB = Kantor::create(['kode' => 'B', 'nama' => 'Kantor B']);
        $admin = User::factory()->admin()->create(['force_password_change' => false]);

        $this->actingAs($admin)->put("/kantor/{$kantorB->id}", ['kode' => 'A', 'nama' => 'Kantor B'])
            ->assertSessionHasErrors('kode');
    }

    public function test_sentinel_kantor_cannot_be_updated_or_toggled(): void
    {
        $sentinel = Kantor::where('kode', Kantor::SENTINEL_ALL_KODE)->firstOrFail();
        $admin = User::factory()->admin()->create(['force_password_change' => false]);

        $this->actingAs($admin)->put("/kantor/{$sentinel->id}", ['kode' => 'ALL', 'nama' => 'Diganti'])->assertNotFound();
        $this->actingAs($admin)->post("/kantor/{$sentinel->id}/toggle-active")->assertNotFound();
    }

    public function test_admin_can_toggle_kantor_active_status(): void
    {
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A', 'is_active' => true]);
        $admin = User::factory()->admin()->create(['force_password_change' => false]);

        $this->actingAs($admin)->post("/kantor/{$kantor->id}/toggle-active")->assertRedirect();
        $this->assertFalse($kantor->fresh()->is_active);
    }

    // ---------------- export ----------------

    public function test_export_excludes_the_sentinel_row(): void
    {
        Excel::fake();
        Excel::matchByRegex();

        Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $admin = User::factory()->admin()->create(['force_password_change' => false]);

        $this->actingAs($admin)->get('/export/kantor/download')->assertOk();

        Excel::assertDownloaded('/data-kantor-.*\.xlsx/', function (KantorExport $export) {
            $names = $export->query()->pluck('nama');

            return $names->contains('Kantor A') && ! $names->contains('Seluruh Kantor');
        });
    }

    // ---------------- KantorImport (ID-based upsert) ----------------

    public function test_import_with_id_renames_the_existing_kantor_instead_of_creating_a_new_one(): void
    {
        $kantor = Kantor::create(['kode' => 'JKT01', 'nama' => 'Kanto Jakarta']);
        $admin = User::factory()->admin()->create(['force_password_change' => false]);

        $file = $this->buildImportFixture([
            [$kantor->id, 'JKT01', 'Kantor Jakarta', 'Ya'],
        ]);

        $response = $this->actingAs($admin)->post('/kantor-import', ['file' => $file]);

        $response->assertRedirect(route('kantor.import.create'));
        $this->assertSame(1, session('import_summary')['imported']);
        $this->assertSame('Kantor Jakarta', $kantor->fresh()->nama);
        $this->assertSame(1, Kantor::where('kode', 'JKT01')->count());
    }

    public function test_import_blank_cells_on_an_update_row_leave_the_existing_value_alone(): void
    {
        $kantor = Kantor::create(['kode' => 'JKT01', 'nama' => 'Kantor Jakarta', 'is_active' => true]);
        $admin = User::factory()->admin()->create(['force_password_change' => false]);

        // Only Nama filled in — Kode/Aktif blank must not clobber existing values.
        $file = $this->buildImportFixture([
            [$kantor->id, '', 'Kantor Jakarta Pusat', ''],
        ]);

        $this->actingAs($admin)->post('/kantor-import', ['file' => $file]);

        $kantor->refresh();
        $this->assertSame('JKT01', $kantor->kode);
        $this->assertSame('Kantor Jakarta Pusat', $kantor->nama);
        $this->assertTrue($kantor->is_active);
    }

    public function test_import_without_id_inserts_a_new_kantor(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);

        $file = $this->buildImportFixture([
            ['', 'BDG01', 'Kantor Bandung', 'Tidak'],
        ]);

        $response = $this->actingAs($admin)->post('/kantor-import', ['file' => $file]);

        $response->assertRedirect(route('kantor.import.create'));
        $this->assertSame(1, session('import_summary')['imported']);
        $this->assertDatabaseHas('kantor', ['kode' => 'BDG01', 'nama' => 'Kantor Bandung', 'is_active' => false]);
    }

    /**
     * The whole point of the 2026-07-23 name-match fallback: a plain
     * "Cabang, Cabang-Cluster, Area" mapping file (no ID column at all) must
     * update the existing Cabang it names, not create a duplicate every time
     * it's re-uploaded.
     */
    public function test_import_without_id_matches_an_existing_kantor_by_exact_nama_instead_of_inserting(): void
    {
        $kantor = Kantor::create(['kode' => 'JKT01', 'nama' => 'Kantor Jakarta']);
        $admin = User::factory()->admin()->create(['force_password_change' => false]);

        $file = $this->buildImportFixture([
            ['', '', 'Kantor Jakarta', ''],
        ]);

        $response = $this->actingAs($admin)->post('/kantor-import', ['file' => $file]);

        $response->assertRedirect(route('kantor.import.create'));
        $this->assertSame(1, session('import_summary')['imported']);
        $this->assertSame(1, Kantor::where('nama', 'Kantor Jakarta')->count());
        $this->assertSame('JKT01', $kantor->fresh()->kode);
    }

    public function test_import_sets_area_and_cabang_cluster_and_blank_cells_leave_them_alone(): void
    {
        $kantor = Kantor::create(['kode' => 'JKT01', 'nama' => 'Kantor Jakarta', 'area' => 'Area Lama']);
        $admin = User::factory()->admin()->create(['force_password_change' => false]);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['ID', 'Kode', 'Cabang', 'Aktif', 'Area', 'Cabang-Cluster'], null, 'A1');
        $sheet->fromArray([[$kantor->id, '', '', '', '', 'Cluster Jakarta']], null, 'A2');
        $path = tempnam(sys_get_temp_dir(), 'kantor_import_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $file = new UploadedFile($path, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $this->actingAs($admin)->post('/kantor-import', ['file' => $file]);

        $kantor->refresh();
        $this->assertSame('Area Lama', $kantor->area); // untouched — blank cell
        $this->assertSame('Cluster Jakarta', $kantor->cabang_cluster);
    }

    /**
     * "Cabang" is the primary heading for the name column now (2026-07-23) —
     * confirms it's actually read, not just tolerated because "Nama" still
     * works as a fallback.
     */
    public function test_import_reads_the_cabang_heading_for_the_name_column(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(['Kode', 'Cabang', 'Area', 'Cabang-Cluster'], null, 'A1');
        $sheet->fromArray([['SBY01', 'Kantor Surabaya', 'Area Jatim', 'Cluster Surabaya']], null, 'A2');
        $path = tempnam(sys_get_temp_dir(), 'kantor_import_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $file = new UploadedFile($path, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $this->actingAs($admin)->post('/kantor-import', ['file' => $file]);

        $this->assertDatabaseHas('kantor', [
            'kode' => 'SBY01', 'nama' => 'Kantor Surabaya',
            'area' => 'Area Jatim', 'cabang_cluster' => 'Cluster Surabaya',
        ]);
    }

    public function test_import_rejects_an_id_that_does_not_exist(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);

        $file = $this->buildImportFixture([
            [999999, 'X', 'Tidak Ada', 'Ya'],
        ]);

        $response = $this->actingAs($admin)->post('/kantor-import', ['file' => $file]);

        $summary = session('import_summary');
        $this->assertSame(0, $summary['imported']);
        $this->assertSame(1, $summary['rejected']);
    }

    public function test_import_rejects_the_sentinel_kantor_id(): void
    {
        $sentinel = Kantor::where('kode', Kantor::SENTINEL_ALL_KODE)->firstOrFail();
        $admin = User::factory()->admin()->create(['force_password_change' => false]);

        $file = $this->buildImportFixture([
            [$sentinel->id, 'ALL', 'Diganti Paksa', 'Ya'],
        ]);

        $response = $this->actingAs($admin)->post('/kantor-import', ['file' => $file]);

        $summary = session('import_summary');
        $this->assertSame(0, $summary['imported']);
        $this->assertSame(1, $summary['rejected']);
        $this->assertSame('Seluruh Kantor', $sentinel->fresh()->nama);
    }

    /**
     * A Kode/Nama collision with a DIFFERENT kantor isn't a validation rule
     * here (see KantorImport's docblock) — it surfaces as a genuine DB save
     * failure through onError()/technical_errors, not $summary['errors'].
     */
    public function test_import_records_a_technical_error_when_renaming_into_a_colliding_kode(): void
    {
        Kantor::create(['kode' => 'DUPE', 'nama' => 'Kantor Dupe']);
        $target = Kantor::create(['kode' => 'JKT01', 'nama' => 'Kantor Jakarta']);
        $admin = User::factory()->admin()->create(['force_password_change' => false]);

        $file = $this->buildImportFixture([
            [$target->id, 'DUPE', 'Kantor Jakarta', 'Ya'],
        ]);

        $response = $this->actingAs($admin)->post('/kantor-import', ['file' => $file]);

        $summary = session('import_summary');
        $this->assertSame(0, $summary['imported']);
        $this->assertNotEmpty($summary['technical_errors']);
        $this->assertSame('JKT01', $target->fresh()->kode);
    }
}
