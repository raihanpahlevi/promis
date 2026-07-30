<?php

namespace Tests\Feature;

use App\Models\Kantor;
use App\Models\Kunjungan;
use App\Models\Poi;
use App\Models\User;
use Database\Factories\PoiFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Summary Kunjungan / Summary Produk — the two reports rebuilt from the
 * "menu tambahan" workbook. What matters here is the shape the spreadsheet
 * defines (Area > Cabang-Cluster > Cabang, each group carrying a subtotal and
 * the table a grand total) and the three formula decisions taken when it was
 * translated: Total Kunjungan counts Closing, %-Tase Closing divides by
 * Jumlah POI, and Summary Produk counts Closing visits only.
 */
class SummaryLaporanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth', 'force.password.change', 'active.kantor'])
            ->group(base_path('routes/laporan.php'));
        Route::getRoutes()->refreshNameLookups();

        Kantor::firstOrCreate(
            ['kode' => Kantor::SENTINEL_ALL_KODE],
            ['nama' => 'Seluruh Kantor', 'is_active' => false],
        );
    }

    private function kantor(string $kode, string $nama, ?string $area, ?string $cluster): Kantor
    {
        return Kantor::create(['kode' => $kode, 'nama' => $nama, 'area' => $area, 'cabang_cluster' => $cluster]);
    }

    private function visit(Kantor $kantor, User $sales, string $hasil, string $tanggal, array $produk = []): Kunjungan
    {
        $poi = PoiFactory::new()->create(['kantor_id' => $kantor->id, 'status' => 'aktif']);
        $kunjungan = Kunjungan::create([
            'poi_id' => $poi->id,
            'sales_id' => $sales->id,
            'tanggal_kunjungan' => $tanggal,
            'hasil' => $hasil,
        ]);
        foreach ($produk as $p) {
            $kunjungan->produkList()->create(['produk' => $p]);
        }

        return $kunjungan;
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['force_password_change' => false]);
    }

    /** @return array<int, array<string, mixed>> */
    private function rowsFrom($response): array
    {
        return $response->viewData('rows');
    }

    private function rowFor(array $rows, string $label): array
    {
        foreach ($rows as $row) {
            if ($row['label'] === $label) {
                return $row;
            }
        }

        $this->fail("Baris '{$label}' tidak ada di laporan.");
    }

    // ---------------- Summary Kunjungan ----------------

    public function test_rows_are_nested_area_cluster_cabang_with_subtotals_and_a_grand_total(): void
    {
        $a1 = $this->kantor('A1', '00 - ALPHA', 'AREA SATU', 'CLUSTER A');
        $a2 = $this->kantor('A2', '01 - BETA', 'AREA SATU', 'CLUSTER A');
        $b1 = $this->kantor('B1', '00 - GAMMA', 'AREA DUA', 'CLUSTER B');

        $sales = User::factory()->create(['force_password_change' => false, 'role' => User::ROLE_SALES]);
        $sales->kantor()->attach([$a1->id, $a2->id, $b1->id]);

        $today = now()->toDateString();
        $this->visit($a1, $sales, Kunjungan::HASIL_CLOSING, $today);
        $this->visit($a2, $sales, Kunjungan::HASIL_BERMINAT, $today);
        $this->visit($b1, $sales, Kunjungan::HASIL_CLOSING, $today);

        $response = $this->actingAs($this->admin())->get('/laporan/summary-kunjungan');
        $response->assertOk();
        $rows = $this->rowsFrom($response);

        // Groups come out alphabetically, so AREA DUA (one cabang) precedes
        // AREA SATU (two) regardless of the order they were created in.
        $this->assertSame(
            ['area', 'cluster', 'cabang', 'area', 'cluster', 'cabang', 'cabang', 'total'],
            array_column($rows, 'level'),
            'Setiap grup harus punya baris subtotal, dan tabel ditutup grand total.',
        );
        $this->assertSame(
            ['AREA DUA', 'CLUSTER B', '00 - GAMMA', 'AREA SATU', 'CLUSTER A', '00 - ALPHA', '01 - BETA', 'TOTAL'],
            array_column($rows, 'label'),
        );

        // Subtotal AREA SATU = kedua cabang di bawahnya.
        $areaSatu = $this->rowFor($rows, 'AREA SATU');
        $this->assertSame(2, $areaSatu['values'][Kunjungan::HASIL_CLOSING]
            + $areaSatu['values'][Kunjungan::HASIL_BERMINAT]);

        $total = $rows[array_key_last($rows)];
        $this->assertSame('total', $total['level']);
        $this->assertSame(2, $total['values'][Kunjungan::HASIL_CLOSING]);
        $this->assertSame(1, $total['values'][Kunjungan::HASIL_BERMINAT]);
    }

    /**
     * The workbook's =SUM(D:H) stopped before the Closing column; a Cabang
     * whose visits all closed would have reported zero.
     */
    public function test_total_kunjungan_includes_closing(): void
    {
        $kantor = $this->kantor('K1', '00 - SATU', 'AREA', 'CLUSTER');
        $sales = User::factory()->create(['force_password_change' => false, 'role' => User::ROLE_SALES]);
        $sales->kantor()->attach($kantor->id);

        $today = now()->toDateString();
        $this->visit($kantor, $sales, Kunjungan::HASIL_CLOSING, $today);
        $this->visit($kantor, $sales, Kunjungan::HASIL_CLOSING, $today);

        $response = $this->actingAs($this->admin())->get('/laporan/summary-kunjungan');
        $row = $this->rowFor($this->rowsFrom($response), '00 - SATU');

        $this->assertSame(2, $row['values'][Kunjungan::HASIL_CLOSING]);
        $response->assertSee('2', false);
    }

    public function test_jumlah_poi_and_jumlah_sales_ignore_the_date_filter_but_visits_follow_it(): void
    {
        $kantor = $this->kantor('K1', '00 - SATU', 'AREA', 'CLUSTER');
        $sales = User::factory()->create(['force_password_change' => false, 'role' => User::ROLE_SALES]);
        $sales->kantor()->attach($kantor->id);

        // One visit inside the window, one well outside it.
        $this->visit($kantor, $sales, Kunjungan::HASIL_CLOSING, now()->toDateString());
        $this->visit($kantor, $sales, Kunjungan::HASIL_CLOSING, now()->subMonths(6)->toDateString());

        $response = $this->actingAs($this->admin())->get('/laporan/summary-kunjungan?'.http_build_query([
            'dari' => now()->startOfMonth()->toDateString(),
            'sampai' => now()->toDateString(),
        ]));
        $row = $this->rowFor($this->rowsFrom($response), '00 - SATU');

        $this->assertSame(1, $row['values'][Kunjungan::HASIL_CLOSING], 'Kunjungan di luar periode tidak boleh ikut.');
        $this->assertSame(2, $row['values']['jumlah_poi'], 'Jumlah POI adalah stok terkini, bukan angka per periode.');
        $this->assertSame(1, $row['values']['jumlah_sales']);
    }

    public function test_kantor_without_area_or_cluster_still_appears_under_a_placeholder(): void
    {
        $this->kantor('K1', '00 - YATIM', null, null);
        PoiFactory::new()->create(['kantor_id' => Kantor::where('kode', 'K1')->value('id'), 'status' => 'aktif']);

        $response = $this->actingAs($this->admin())->get('/laporan/summary-kunjungan');
        $labels = array_column($this->rowsFrom($response), 'label');

        $this->assertContains('Tanpa Area', $labels);
        $this->assertContains('Tanpa Cluster', $labels);
        $this->assertContains('00 - YATIM', $labels, 'Cabang tanpa Area tidak boleh hilang dari laporan.');
    }

    // ---------------- Summary Produk ----------------

    public function test_summary_produk_counts_products_from_closing_visits_only(): void
    {
        $kantor = $this->kantor('K1', '00 - SATU', 'AREA', 'CLUSTER');
        $sales = User::factory()->create(['force_password_change' => false, 'role' => User::ROLE_SALES]);
        $sales->kantor()->attach($kantor->id);

        $today = now()->toDateString();
        $this->visit($kantor, $sales, Kunjungan::HASIL_CLOSING, $today, ['Tabungan', 'QRIS']);
        // Same products offered but the visit didn't close — must not count.
        $this->visit($kantor, $sales, Kunjungan::HASIL_BERMINAT, $today, ['Tabungan', 'QRIS']);

        $response = $this->actingAs($this->admin())->get('/laporan/summary-produk');
        $response->assertOk();
        $row = $this->rowFor($this->rowsFrom($response), '00 - SATU');

        $this->assertSame(1, $row['values']['Tabungan']);
        $this->assertSame(1, $row['values']['QRIS']);
        $this->assertSame(0, $row['values']['Giro']);
    }

    public function test_summary_produk_subtotals_roll_up_the_hierarchy(): void
    {
        $a1 = $this->kantor('A1', '00 - ALPHA', 'AREA SATU', 'CLUSTER A');
        $a2 = $this->kantor('A2', '01 - BETA', 'AREA SATU', 'CLUSTER A');
        $sales = User::factory()->create(['force_password_change' => false, 'role' => User::ROLE_SALES]);
        $sales->kantor()->attach([$a1->id, $a2->id]);

        $today = now()->toDateString();
        $this->visit($a1, $sales, Kunjungan::HASIL_CLOSING, $today, ['Tabungan']);
        $this->visit($a2, $sales, Kunjungan::HASIL_CLOSING, $today, ['Tabungan', 'KUR']);

        $rows = $this->rowsFrom($this->actingAs($this->admin())->get('/laporan/summary-produk'));

        $this->assertSame(2, $this->rowFor($rows, 'CLUSTER A')['values']['Tabungan']);
        $this->assertSame(1, $this->rowFor($rows, 'CLUSTER A')['values']['KUR']);
        $this->assertSame(2, $rows[array_key_last($rows)]['values']['Tabungan']);
    }

    // ---------------- Access ----------------

    public function test_admin_final_only_sees_their_own_kantor_and_sales_is_blocked(): void
    {
        $mine = $this->kantor('K1', '00 - PUNYAKU', 'AREA', 'CLUSTER');
        $other = $this->kantor('K2', '01 - PUNYAORANG', 'AREA', 'CLUSTER');

        $adminFinal = User::factory()->adminFinal()->create(['force_password_change' => false]);
        $adminFinal->kantor()->attach($mine->id);

        foreach (['/laporan/summary-kunjungan', '/laporan/summary-produk'] as $url) {
            $labels = array_column($this->rowsFrom($this->actingAs($adminFinal)->get($url)), 'label');
            $this->assertContains('00 - PUNYAKU', $labels);
            $this->assertNotContains('01 - PUNYAORANG', $labels, "Kantor orang lain bocor di {$url}");
        }

        $sales = User::factory()->create(['force_password_change' => false, 'role' => User::ROLE_SALES]);
        $sales->kantor()->attach($mine->id);
        $this->actingAs($sales)->get('/laporan/summary-kunjungan')->assertForbidden();
        $this->actingAs($sales)->get('/laporan/summary-produk')->assertForbidden();
    }
}
