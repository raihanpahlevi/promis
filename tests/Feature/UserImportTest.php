<?php

namespace Tests\Feature;

use App\Imports\UserImport;
use App\Models\Kantor;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\Concerns\RegistersUserRoutes;
use Tests\TestCase;

class UserImportTest extends TestCase
{
    use RefreshDatabase;
    use RegistersUserRoutes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerUserRoutes();

        Kantor::firstOrCreate(
            ['kode' => Kantor::SENTINEL_ALL_KODE],
            ['nama' => 'Seluruh Kantor', 'is_active' => false],
        );

        // Unit is now a master list (like kantor) rather than free text — the
        // fixtures below use these two names, so they must exist and be active
        // for the import to accept them.
        Unit::create(['nama' => 'STAFF', 'is_active' => true]);
        Unit::create(['nama' => 'BRANCH MANAGER', 'is_active' => true]);
    }

    /**
     * Builds a throwaway .xlsx fixture matching the real template's shape: a
     * "Data User" sheet with the exact NPP/Nama Lengkap/Unit / Jabatan/Role
     * Sistem/Kantor header row, plus a decoy "Petunjuk" sheet (mirrors the
     * real Template_Import_User_PROMIS.xlsx layout) so the WithMultipleSheets
     * restriction to "Data User" is actually exercised.
     *
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function buildFixture(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet();

        $petunjuk = $spreadsheet->getActiveSheet();
        $petunjuk->setTitle('Petunjuk');
        $petunjuk->setCellValue('A1', 'Ini bukan data, jangan diimport.');

        $dataUser = $spreadsheet->createSheet();
        $dataUser->setTitle('Data User');
        $dataUser->fromArray(
            ['NPP', 'Nama Lengkap', 'Unit / Jabatan', 'Role Sistem', 'Kantor'],
            null,
            'A1'
        );
        $dataUser->fromArray($rows, null, 'A2');

        $path = tempnam(sys_get_temp_dir(), 'user_import_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /**
     * Same fix/reasoning as PoiImportTest's equivalent — onError() must walk
     * importedCount() back down and record the exception rather than letting
     * a runtime save failure propagate and abort the whole import.
     */
    public function test_on_error_reconciles_imported_count_and_records_the_exception(): void
    {
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $import = new UserImport();

        $import->model([
            'npp' => '1234567', 'nama_lengkap' => 'User Satu', 'unit_jabatan' => 'STAFF',
            'role_sistem' => User::ROLE_SALES, 'kantor' => 'Kantor A',
        ]);
        $this->assertSame(1, $import->importedCount());

        $exception = new RuntimeException('Simulated DB failure');
        $import->onError($exception);

        $this->assertSame(0, $import->importedCount());
        $this->assertSame([$exception], $import->errors());
    }

    public function test_admin_import_accepts_valid_rows_and_rejects_invalid_ones_with_reasons(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $kantorA = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $kantorB = Kantor::create(['kode' => 'B', 'nama' => 'Kantor B']);
        User::factory()->create(['npp' => '1000001', 'force_password_change' => false]);

        $file = $this->buildFixture([
            ['2000001', 'User Valid Satu', 'STAFF', User::ROLE_SALES, 'Kantor A'],
            ['2000002', 'User Multi Kantor', 'BRANCH MANAGER', User::ROLE_ADMIN_FINAL, 'Kantor A, Kantor B'],
            ['1000001', 'NPP Sudah Ada', 'STAFF', User::ROLE_SALES, 'Kantor A'],
            ['2000003', 'Role Salah', 'STAFF', 'super_admin', 'Kantor A'],
            ['2000004', 'Kantor Salah', 'STAFF', User::ROLE_SALES, 'Kantor Tidak Ada'],
        ]);

        $response = $this->actingAs($admin)->post('/user-import', ['file' => $file]);

        $response->assertRedirect(route('user.import.create'));
        $summary = session('import_summary');

        $this->assertSame(2, $summary['imported']);
        $this->assertSame(3, $summary['rejected']);

        $reasons = collect($summary['errors'])->flatMap(fn ($e) => $e['errors'])->implode(' | ');
        $this->assertStringContainsString("NPP '1000001' sudah terdaftar di sistem.", $reasons);
        $this->assertStringContainsString('Kantor tidak ditemukan di master kantor: Kantor Tidak Ada.', $reasons);

        $valid1 = User::where('npp', '2000001')->first();
        $this->assertNotNull($valid1);
        $this->assertTrue(Hash::check('2000001', $valid1->password));
        $this->assertTrue($valid1->force_password_change);
        $this->assertTrue($valid1->is_active);
        $this->assertSame(User::ROLE_SALES, $valid1->role);
        $this->assertTrue($valid1->hasKantor($kantorA->id));

        $valid2 = User::where('npp', '2000002')->first();
        $this->assertNotNull($valid2);
        $this->assertTrue($valid2->hasKantor($kantorA->id));
        $this->assertTrue($valid2->hasKantor($kantorB->id));
        $this->assertSame(2, $valid2->kantor()->count());

        $this->assertDatabaseMissing('users', ['npp' => '2000003']);
        $this->assertDatabaseMissing('users', ['npp' => '2000004']);
    }

    public function test_import_rejects_duplicate_npp_within_the_same_file_and_still_imports_the_first_occurrence(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);

        $file = $this->buildFixture([
            ['3000001', 'Pertama', 'STAFF', User::ROLE_SALES, 'Kantor A'],
            ['3000001', 'Duplikat Di File', 'STAFF', User::ROLE_SALES, 'Kantor A'],
        ]);

        $response = $this->actingAs($admin)->post('/user-import', ['file' => $file]);

        $summary = session('import_summary');
        $this->assertSame(1, $summary['imported']);
        $this->assertSame(1, $summary['rejected']);

        $reasons = collect($summary['errors'])->flatMap(fn ($e) => $e['errors'])->implode(' | ');
        $this->assertStringContainsString('duplikat dengan baris', $reasons);

        $this->assertSame(1, User::where('npp', '3000001')->count());
        $this->assertDatabaseHas('users', ['npp' => '3000001', 'nama_lengkap' => 'Pertama']);
    }

    /**
     * Product decision (explicit, 2026-07-15): Unit / Jabatan is the only
     * field allowed to be blank. A non-blank, unrecognized unit auto-creates
     * a new Unit instead of rejecting the row (mirrors PoiImport's kantor
     * auto-create) — every other field (NPP, Nama Lengkap, Role Sistem,
     * Kantor) still must be filled in and resolve to something real.
     */
    public function test_admin_import_allows_a_blank_unit_and_auto_creates_an_unrecognized_one(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);

        $file = $this->buildFixture([
            ['5000001', 'Tanpa Unit', '', User::ROLE_SALES, 'Kantor A'],
            ['5000002', 'Unit Baru Satu', 'Sales Officer', User::ROLE_SALES, 'Kantor A'],
            ['5000003', 'Unit Baru Dua', '  sales officer  ', User::ROLE_SALES, 'Kantor A'],
        ]);

        $response = $this->actingAs($admin)->post('/user-import', ['file' => $file]);

        $response->assertRedirect(route('user.import.create'));
        $summary = session('import_summary');

        $this->assertSame(3, $summary['imported']);
        $this->assertSame(0, $summary['rejected']);

        $this->assertNull(User::where('npp', '5000001')->value('unit_id'));

        $unit = Unit::where('nama', 'Sales Officer')->first();
        $this->assertNotNull($unit);
        $this->assertTrue($unit->is_active);
        // Both differently-cased/whitespaced rows resolve to the SAME new
        // unit — not two separate ones.
        $this->assertSame(1, Unit::where('nama', 'Sales Officer')->count());
        $this->assertSame($unit->id, User::where('npp', '5000002')->value('unit_id'));
        $this->assertSame($unit->id, User::where('npp', '5000003')->value('unit_id'));

        $this->assertTrue(User::where('npp', '5000001')->first()->hasKantor($kantor->id));
    }

    /**
     * Role Sistem and Kantor still get normalized (case/whitespace-tolerant)
     * matching, unlike Unit they never fall back to free text or auto-create
     * — RBAC role and kantor master data are both security/scope-relevant.
     */
    public function test_admin_import_matches_role_and_kantor_case_and_whitespace_insensitively(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);

        $file = $this->buildFixture([
            ['6000001', 'Beda Huruf', 'STAFF', strtoupper(User::ROLE_SALES), '  kantor a  '],
        ]);

        $response = $this->actingAs($admin)->post('/user-import', ['file' => $file]);

        $response->assertRedirect(route('user.import.create'));
        $this->assertSame(1, session('import_summary')['imported']);

        $user = User::where('npp', '6000001')->first();
        $this->assertNotNull($user);
        $this->assertSame(User::ROLE_SALES, $user->role);
        $this->assertTrue($user->hasKantor($kantor->id));
    }

    /**
     * A hand-built file with just one sheet (any tab name — not necessarily
     * "Data User") must still import instead of requiring an exact sheet
     * name match, same as PoiImportController.
     */
    public function test_single_sheet_file_imports_regardless_of_its_tab_name(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Sheet1');
        $sheet->fromArray(['NPP', 'Nama Lengkap', 'Unit / Jabatan', 'Role Sistem', 'Kantor'], null, 'A1');
        $sheet->fromArray([['7000001', 'Satu Sheet', 'STAFF', User::ROLE_SALES, 'Kantor A']], null, 'A2');

        $path = tempnam(sys_get_temp_dir(), 'user_import_').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $file = new UploadedFile($path, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response = $this->actingAs($admin)->post('/user-import', ['file' => $file]);

        $response->assertRedirect(route('user.import.create'));
        $this->assertSame(1, session('import_summary')['imported']);
        $this->assertTrue(User::where('npp', '7000001')->first()->hasKantor($kantor->id));
    }

    public function test_admin_final_cannot_access_user_import(): void
    {
        $adminFinal = User::factory()->adminFinal()->create(['force_password_change' => false]);

        $this->actingAs($adminFinal)->get('/user-import')->assertForbidden();

        $file = $this->buildFixture([
            ['4000001', 'Coba Import', 'STAFF', User::ROLE_SALES, 'Kantor A'],
        ]);

        $this->actingAs($adminFinal)->post('/user-import', ['file' => $file])->assertForbidden();
        $this->assertDatabaseMissing('users', ['npp' => '4000001']);
    }

    public function test_sales_cannot_access_user_import(): void
    {
        $sales = User::factory()->create(['force_password_change' => false, 'role' => User::ROLE_SALES]);
        $kantor = Kantor::create(['kode' => 'X', 'nama' => 'Kantor X']);
        $sales->kantor()->attach($kantor->id);

        $this->actingAs($sales)->get('/user-import')->assertForbidden();

        $file = $this->buildFixture([
            ['4000002', 'Coba Import', 'STAFF', User::ROLE_SALES, 'Kantor X'],
        ]);

        $this->actingAs($sales)->post('/user-import', ['file' => $file])->assertForbidden();
        $this->assertDatabaseMissing('users', ['npp' => '4000002']);
    }

    public function test_template_download_serves_a_real_downloadable_xlsx_file(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);

        $response = $this->actingAs($admin)->get('/user-import/template');

        $response->assertOk();

        // Assert this is an actual file-download response, not just a 200 —
        // the brief flags a prior known bug where the template route
        // returned the wrong response type.
        $this->assertInstanceOf(
            \Symfony\Component\HttpFoundation\BinaryFileResponse::class,
            $response->baseResponse
        );
        $disposition = $response->headers->get('content-disposition');
        $this->assertNotNull($disposition);
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('Template_Import_User_PROMIS.xlsx', $disposition);

        $expectedSize = filesize(base_path('Template_Import_User_PROMIS.xlsx'));
        $this->assertSame((string) $expectedSize, $response->headers->get('content-length'));
    }
}
