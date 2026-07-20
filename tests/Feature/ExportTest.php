<?php

namespace Tests\Feature;

use App\Exports\KunjunganExport;
use App\Exports\PoiExport;
use App\Models\Kantor;
use App\Models\Kunjungan;
use App\Models\Poi;
use App\Models\User;
use Database\Factories\PoiFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Kantor::firstOrCreate(
            ['kode' => Kantor::SENTINEL_ALL_KODE],
            ['nama' => 'Seluruh Kantor', 'is_active' => false],
        );
    }

    public function test_sales_cannot_access_export_download(): void
    {
        $sales = User::factory()->create(['force_password_change' => false]);

        $this->actingAs($sales)->get('/export/kunjungan/download')->assertForbidden();
    }

    /**
     * No standalone "Export Data" browsing page anymore — merged into Riwayat
     * Kunjungan as a button. Covered by KunjunganTest's own coverage of that
     * page; this class only tests the download endpoint from here on.
     */
    public function test_poi_name_filter_narrows_the_export(): void
    {
        Excel::fake();
        Excel::matchByRegex();

        $kantor = Kantor::create(['kode' => 'K1', 'nama' => 'Kantor Satu']);
        $poiMatch = PoiFactory::new()->create(['kantor_id' => $kantor->id, 'nama_poi' => 'Toko Sejahtera']);
        $poiOther = PoiFactory::new()->create(['kantor_id' => $kantor->id, 'nama_poi' => 'Warung Makmur']);
        $sales = User::factory()->create(['force_password_change' => false]);

        Kunjungan::create(['poi_id' => $poiMatch->id, 'sales_id' => $sales->id, 'tanggal_kunjungan' => now()->toDateString(), 'hasil' => Kunjungan::HASIL_CLOSING]);
        Kunjungan::create(['poi_id' => $poiOther->id, 'sales_id' => $sales->id, 'tanggal_kunjungan' => now()->toDateString(), 'hasil' => Kunjungan::HASIL_CLOSING]);

        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $this->actingAs($admin)->get('/export/kunjungan/download?poi=Sejahtera')->assertOk();

        Excel::assertDownloaded('/rekap-kunjungan-.*\.xlsx/', function (KunjunganExport $export) use ($poiMatch) {
            $rows = $export->query()->get();

            return $rows->count() === 1 && $rows->first()->poi_id === $poiMatch->id;
        });
    }

    public function test_admin_final_export_is_scoped_to_their_own_kantor_and_ignores_a_forged_filter(): void
    {
        Excel::fake();
        Excel::matchByRegex();

        $mine = Kantor::create(['kode' => 'MINE', 'nama' => 'Kantor Saya']);
        $other = Kantor::create(['kode' => 'OTHER', 'nama' => 'Kantor Lain']);

        $poiMine = PoiFactory::new()->create(['kantor_id' => $mine->id]);
        $poiOther = PoiFactory::new()->create(['kantor_id' => $other->id]);
        $sales = User::factory()->create(['force_password_change' => false]);

        Kunjungan::create(['poi_id' => $poiMine->id, 'sales_id' => $sales->id, 'tanggal_kunjungan' => now()->toDateString(), 'hasil' => Kunjungan::HASIL_CLOSING]);
        Kunjungan::create(['poi_id' => $poiOther->id, 'sales_id' => $sales->id, 'tanggal_kunjungan' => now()->toDateString(), 'hasil' => Kunjungan::HASIL_CLOSING]);

        $adminFinal = User::factory()->adminFinal()->create(['force_password_change' => false]);
        $adminFinal->kantor()->attach($mine->id);

        // Forging kantor_id to the other kantor must not narrow the export to it.
        $this->actingAs($adminFinal)->get('/export/kunjungan/download?kantor_id='.$other->id)->assertOk();

        Excel::assertDownloaded('/rekap-kunjungan-.*\.xlsx/', function (KunjunganExport $export) use ($poiMine) {
            $rows = $export->query()->get();

            return $rows->count() === 1 && $rows->first()->poi_id === $poiMine->id;
        });
    }

    public function test_export_includes_the_poi_pic_column(): void
    {
        $kantor = Kantor::create(['kode' => 'K1', 'nama' => 'Kantor Satu']);
        $poi = PoiFactory::new()->create(['kantor_id' => $kantor->id, 'pic' => 'Budi Santoso (BTRM)']);
        $sales = User::factory()->create(['force_password_change' => false]);

        Kunjungan::create([
            'poi_id' => $poi->id, 'sales_id' => $sales->id,
            'tanggal_kunjungan' => now()->toDateString(), 'hasil' => Kunjungan::HASIL_CLOSING,
        ]);

        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $response = $this->actingAs($admin)->get('/export/kunjungan/download');

        $response->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $sheet = IOFactory::load($path)->getActiveSheet();

        $this->assertSame('PIC', $sheet->getCell('H1')->getValue());
        $this->assertSame('Budi Santoso (BTRM)', $sheet->getCell('H2')->getValue());
    }

    public function test_export_produces_a_real_xlsx_with_formula_injection_escaped(): void
    {
        $kantor = Kantor::create(['kode' => 'K1', 'nama' => 'Kantor Satu']);
        // A malicious POI name a user could type — must not survive into the
        // spreadsheet as a live formula when opened in Excel/Sheets.
        $poi = PoiFactory::new()->create(['kantor_id' => $kantor->id, 'nama_poi' => '=cmd|"/c calc"!A1']);
        $sales = User::factory()->create(['force_password_change' => false]);

        Kunjungan::create([
            'poi_id' => $poi->id, 'sales_id' => $sales->id,
            'tanggal_kunjungan' => now()->toDateString(), 'hasil' => Kunjungan::HASIL_CLOSING,
        ]);

        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $response = $this->actingAs($admin)->get('/export/kunjungan/download');

        $response->assertOk();
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\BinaryFileResponse::class, $response->baseResponse);

        $path = $response->baseResponse->getFile()->getPathname();
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();

        // Header row + one data row.
        $this->assertSame('Tanggal', $sheet->getCell('A1')->getValue());
        $poiNameCell = $sheet->getCell('C2')->getValue();
        $this->assertStringStartsWith("'=", $poiNameCell, 'formula-leading value must be quote-escaped, got: '.$poiNameCell);
    }

    // ---------------- POI export ----------------

    public function test_sales_cannot_access_poi_export_download(): void
    {
        $sales = User::factory()->create(['force_password_change' => false]);

        $this->actingAs($sales)->get('/export/poi/download')->assertForbidden();
    }

    /**
     * The exported file is meant to round-trip back through PoiImport as an
     * update (see PoiImport's ID-based upsert) — the ID column is what makes
     * that possible, so it has to be present, correct, and in the first
     * column PoiImport's heading-row reader expects ("ID").
     */
    public function test_poi_export_includes_the_id_column_for_round_tripping_back_through_import(): void
    {
        $kantor = Kantor::create(['kode' => 'K1', 'nama' => 'Kantor Satu']);
        $poi = PoiFactory::new()->create(['kantor_id' => $kantor->id, 'nama_poi' => 'Toko Satu', 'pic' => 'Budi']);

        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $response = $this->actingAs($admin)->get('/export/poi/download');

        $response->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $sheet = IOFactory::load($path)->getActiveSheet();

        $this->assertSame('ID', $sheet->getCell('A1')->getValue());
        $this->assertSame('Nama', $sheet->getCell('B1')->getValue());
        $this->assertEquals($poi->id, $sheet->getCell('A2')->getValue());
        $this->assertSame('Toko Satu', $sheet->getCell('B2')->getValue());
        $this->assertSame('Outlet', $sheet->getCell('G1')->getValue());
        $this->assertSame('Kantor Satu', $sheet->getCell('G2')->getValue());
    }

    public function test_poi_export_area_filter_narrows_the_query(): void
    {
        Excel::fake();
        Excel::matchByRegex();

        $kantor = Kantor::create(['kode' => 'K1', 'nama' => 'Kantor Satu']);
        $poiMatch = PoiFactory::new()->create(['kantor_id' => $kantor->id, 'area' => Poi::AREA_OPTIONS[0]]);
        PoiFactory::new()->create(['kantor_id' => $kantor->id, 'area' => Poi::AREA_OPTIONS[1]]);

        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $this->actingAs($admin)->get('/export/poi/download?area='.urlencode(Poi::AREA_OPTIONS[0]))->assertOk();

        Excel::assertDownloaded('/data-poi-.*\.xlsx/', function (PoiExport $export) use ($poiMatch) {
            $rows = $export->query()->get();

            return $rows->count() === 1 && $rows->first()->id === $poiMatch->id;
        });
    }

    public function test_admin_final_poi_export_is_scoped_to_their_own_kantor_and_ignores_a_forged_filter(): void
    {
        Excel::fake();
        Excel::matchByRegex();

        $mine = Kantor::create(['kode' => 'MINE', 'nama' => 'Kantor Saya']);
        $other = Kantor::create(['kode' => 'OTHER', 'nama' => 'Kantor Lain']);

        $poiMine = PoiFactory::new()->create(['kantor_id' => $mine->id]);
        PoiFactory::new()->create(['kantor_id' => $other->id]);

        $adminFinal = User::factory()->adminFinal()->create(['force_password_change' => false]);
        $adminFinal->kantor()->attach($mine->id);

        // Forging kantor to the other kantor must not narrow the export to it.
        $this->actingAs($adminFinal)->get('/export/poi/download?kantor='.$other->id)->assertOk();

        Excel::assertDownloaded('/data-poi-.*\.xlsx/', function (PoiExport $export) use ($poiMine) {
            $rows = $export->query()->get();

            return $rows->count() === 1 && $rows->first()->id === $poiMine->id;
        });
    }
}
