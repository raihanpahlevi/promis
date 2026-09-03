<?php

namespace Tests\Feature;

use App\Models\Kantor;
use App\Models\Kunjungan;
use App\Models\Poi;
use App\Models\Unit;
use App\Models\User;
use Database\Factories\PoiFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PhpOffice\PhpSpreadsheet\IOFactory;
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

    /**
     * Sales are counted with the same rule Rekap Sales uses
     * (LaporanController::relevantUserQuery) — role sales/admin_final AND an
     * active unit — so every fixture user needs a unit or they legitimately
     * won't appear in the Jumlah Sales column.
     */
    private function salesFor(Kantor ...$kantor): User
    {
        $unit = Unit::firstOrCreate(['nama' => 'SALES'], ['is_active' => true]);
        $user = User::factory()->create([
            'force_password_change' => false,
            'role' => User::ROLE_SALES,
            'unit_id' => $unit->id,
        ]);
        $user->kantor()->attach(collect($kantor)->pluck('id')->all());

        return $user;
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

        $sales = $this->salesFor($a1, $a2, $b1);

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
        $sales = $this->salesFor($kantor);

        $today = now()->toDateString();
        $this->visit($kantor, $sales, Kunjungan::HASIL_CLOSING, $today);
        $this->visit($kantor, $sales, Kunjungan::HASIL_CLOSING, $today);

        $response = $this->actingAs($this->admin())->get('/laporan/summary-kunjungan');
        $row = $this->rowFor($this->rowsFrom($response), '00 - SATU');

        $this->assertSame(2, $row['values'][Kunjungan::HASIL_CLOSING]);
    }

    public function test_jumlah_poi_and_jumlah_sales_ignore_the_date_filter_but_visits_follow_it(): void
    {
        $kantor = $this->kantor('K1', '00 - SATU', 'AREA', 'CLUSTER');
        $sales = $this->salesFor($kantor);

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

    /**
     * Jumlah Sales is a headcount, so it can't be added up the tree: one sales
     * holding two Cabang used to be reported as two people at Cluster, Area
     * and grand-total level. Every group now counts the union of its members.
     */
    public function test_a_sales_holding_two_cabang_is_counted_once_in_every_subtotal(): void
    {
        $a1 = $this->kantor('A1', '00 - ALPHA', 'AREA SATU', 'CLUSTER A');
        $a2 = $this->kantor('A2', '01 - BETA', 'AREA SATU', 'CLUSTER A');
        $this->salesFor($a1, $a2);

        $rows = $this->rowsFrom($this->actingAs($this->admin())->get('/laporan/summary-kunjungan'));

        $this->assertSame(1, $this->rowFor($rows, '00 - ALPHA')['values']['jumlah_sales']);
        $this->assertSame(1, $this->rowFor($rows, '01 - BETA')['values']['jumlah_sales']);
        $this->assertSame(1, $this->rowFor($rows, 'CLUSTER A')['values']['jumlah_sales'], 'Satu orang, bukan dua.');
        $this->assertSame(1, $this->rowFor($rows, 'AREA SATU')['values']['jumlah_sales']);
        $this->assertSame(1, $this->rowFor($rows, 'TOTAL')['values']['jumlah_sales']);
    }

    /**
     * Same population as Rekap Sales (relevantUserQuery): an inactive user, an
     * `admin`, and a user whose unit was deactivated are all out of scope.
     */
    public function test_jumlah_sales_matches_the_rekap_sales_population(): void
    {
        $kantor = $this->kantor('K1', '00 - SATU', 'AREA', 'CLUSTER');
        $aktif = Unit::create(['nama' => 'AKTIF', 'is_active' => true]);
        $mati = Unit::create(['nama' => 'MATI', 'is_active' => false]);

        $attach = function (User $u) use ($kantor) {
            $u->kantor()->attach($kantor->id);
        };
        $attach(User::factory()->create(['force_password_change' => false, 'role' => User::ROLE_SALES, 'unit_id' => $aktif->id]));
        $attach(User::factory()->create(['force_password_change' => false, 'role' => User::ROLE_SALES, 'unit_id' => $aktif->id, 'is_active' => false]));
        $attach(User::factory()->create(['force_password_change' => false, 'role' => User::ROLE_SALES, 'unit_id' => $mati->id]));
        $attach(User::factory()->admin()->create(['force_password_change' => false, 'unit_id' => $aktif->id]));

        $rows = $this->rowsFrom($this->actingAs($this->admin())->get('/laporan/summary-kunjungan'));

        $this->assertSame(1, $this->rowFor($rows, '00 - SATU')['values']['jumlah_sales']);
    }

    /**
     * Jumlah POI only counts aktif POI, so the visit columns beside it must
     * too — otherwise a row shows visits against POI it claims don't exist and
     * %-Tase Closing can pass 100%.
     */
    public function test_visits_against_a_deactivated_poi_are_excluded_like_the_poi_count_is(): void
    {
        $kantor = $this->kantor('K1', '00 - SATU', 'AREA', 'CLUSTER');
        $sales = $this->salesFor($kantor);

        $kunjungan = $this->visit($kantor, $sales, Kunjungan::HASIL_CLOSING, now()->toDateString(), ['Tabungan']);
        $kunjungan->poi->update(['status' => 'nonaktif']);

        $rows = $this->rowsFrom($this->actingAs($this->admin())->get('/laporan/summary-kunjungan'));
        $row = $this->rowFor($rows, '00 - SATU');
        $this->assertSame(0, $row['values']['jumlah_poi']);
        $this->assertSame(0, $row['values'][Kunjungan::HASIL_CLOSING], 'POI nonaktif tidak boleh nyumbang kunjungan.');

        $produkRows = $this->rowsFrom($this->actingAs($this->admin())->get('/laporan/summary-produk'));
        $this->assertSame(0, $this->rowFor($produkRows, '00 - SATU')['values']['Tabungan']);
    }

    public function test_an_unparseable_date_is_rejected_instead_of_crashing_the_page(): void
    {
        $this->kantor('K1', '00 - SATU', 'AREA', 'CLUSTER');
        $admin = $this->admin();

        foreach (['/laporan/summary-kunjungan', '/laporan/summary-produk'] as $url) {
            $this->actingAs($admin)->get($url.'?dari=abc')->assertSessionHasErrors('dari');
            $this->actingAs($admin)->get($url.'?dari[]=2026-01-01')->assertSessionHasErrors('dari');
        }
    }

    public function test_grand_total_equals_the_sum_of_the_area_subtotals(): void
    {
        $a = $this->kantor('A1', '00 - ALPHA', 'AREA SATU', 'CLUSTER A');
        $b = $this->kantor('B1', '00 - GAMMA', 'AREA DUA', 'CLUSTER B');
        $sales = $this->salesFor($a, $b);

        $today = now()->toDateString();
        $this->visit($a, $sales, Kunjungan::HASIL_CLOSING, $today);
        $this->visit($b, $sales, Kunjungan::HASIL_BERMINAT, $today);
        $this->visit($b, $sales, Kunjungan::HASIL_CLOSING, $today);

        $rows = $this->rowsFrom($this->actingAs($this->admin())->get('/laporan/summary-kunjungan'));
        $areas = array_values(array_filter($rows, fn ($r) => $r['level'] === 'area'));
        $total = $this->rowFor($rows, 'TOTAL');

        foreach (array_merge(['jumlah_poi'], [Kunjungan::HASIL_CLOSING, Kunjungan::HASIL_BERMINAT]) as $column) {
            $this->assertSame(
                array_sum(array_column(array_column($areas, 'values'), $column)),
                $total['values'][$column],
                "Grand total kolom {$column} tidak sama dengan jumlah subtotal Area.",
            );
        }
    }

    // ---------------- Summary Produk ----------------

    public function test_summary_produk_counts_products_from_closing_visits_only(): void
    {
        $kantor = $this->kantor('K1', '00 - SATU', 'AREA', 'CLUSTER');
        $sales = $this->salesFor($kantor);

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
        $sales = $this->salesFor($a1, $a2);

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

        $sales = $this->salesFor($mine);
        $this->actingAs($sales)->get('/laporan/summary-kunjungan')->assertForbidden();
        $this->actingAs($sales)->get('/laporan/summary-produk')->assertForbidden();
    }

    // ---------------- Export Excel Summary Kunjungan (2026-09-03) ----------------

    /**
     * Reads the exported sheet back as [heading => value] rows keyed by the
     * Report Sales label, skipping the period caption in row 1.
     *
     * @return array<string, array<string, mixed>>
     */
    private function sheetFrom($response): array
    {
        $path = $response->baseResponse->getFile()->getPathname();
        $raw = IOFactory::load($path)->getActiveSheet()->toArray();

        $head = $raw[1];
        $out = [];
        foreach (array_slice($raw, 2) as $baris) {
            if (($baris[1] ?? null) === null || $baris[1] === '') {
                continue;
            }
            $out[$baris[1]] = array_combine($head, $baris);
        }

        return $out;
    }

    public function test_export_carries_the_same_numbers_the_screen_shows(): void
    {
        $kantor = $this->kantor('K1', 'Cabang Satu', 'AREA SATU', 'CLUSTER A');
        $sales = $this->salesFor($kantor);
        $this->visit($kantor, $sales, Kunjungan::HASIL_CLOSING, '2026-03-05');
        $this->visit($kantor, $sales, Kunjungan::HASIL_CLOSING, '2026-03-06');
        $this->visit($kantor, $sales, Kunjungan::HASIL_BERMINAT, '2026-03-07');

        $admin = $this->admin();
        $q = '?dari=2026-03-01&sampai=2026-03-31';

        $layar = $this->rowFor(
            $this->rowsFrom($this->actingAs($admin)->get('/laporan/summary-kunjungan'.$q)),
            'Cabang Satu',
        );

        $response = $this->actingAs($admin)->get('/laporan/summary-kunjungan/export'.$q);
        $response->assertOk();
        $sheet = $this->sheetFrom($response);

        $this->assertArrayHasKey('Cabang Satu', $sheet);
        $baris = $sheet['Cabang Satu'];

        $this->assertSame('Cabang', $baris['Level']);
        $this->assertEquals($layar['values']['jumlah_poi'], $baris['Jumlah POI']);
        $this->assertEquals($layar['values']['jumlah_sales'], $baris['Jumlah Sales']);
        $this->assertEquals($layar['values']['total_kunjungan'], $baris['Total Kunjungan']);
        $this->assertEquals($layar['values']['persen_closing'], $baris['%-Tase Closing']);
        $this->assertEquals(3, $baris['Total Kunjungan']);
    }

    /**
     * The sheet interleaves Cabang rows with Area/Cluster subtotals and a
     * grand TOTAL. Without a Level column, summing a column in Excel silently
     * counts every figure about four times over.
     */
    public function test_export_labels_which_rows_are_subtotals(): void
    {
        $kantor = $this->kantor('K1', 'Cabang Satu', 'AREA SATU', 'CLUSTER A');
        $sales = $this->salesFor($kantor);
        $this->visit($kantor, $sales, Kunjungan::HASIL_CLOSING, '2026-03-05');

        $response = $this->actingAs($this->admin())
            ->get('/laporan/summary-kunjungan/export?dari=2026-03-01&sampai=2026-03-31');
        $sheet = $this->sheetFrom($response);

        $this->assertSame('Area', $sheet['AREA SATU']['Level']);
        $this->assertSame('Cabang-Cluster', $sheet['CLUSTER A']['Level']);
        $this->assertSame('Cabang', $sheet['Cabang Satu']['Level']);
        $this->assertSame('TOTAL', $sheet['TOTAL']['Level']);
    }

    public function test_export_respects_the_date_filter_and_names_the_period(): void
    {
        $kantor = $this->kantor('K1', 'Cabang Satu', 'AREA SATU', 'CLUSTER A');
        $sales = $this->salesFor($kantor);
        $this->visit($kantor, $sales, Kunjungan::HASIL_CLOSING, '2026-03-05');
        // Outside the window below — must not be counted.
        $this->visit($kantor, $sales, Kunjungan::HASIL_CLOSING, '2026-05-05');

        $response = $this->actingAs($this->admin())
            ->get('/laporan/summary-kunjungan/export?dari=2026-03-01&sampai=2026-03-31');

        $raw = IOFactory::load($response->baseResponse->getFile()->getPathname())
            ->getActiveSheet()->toArray();

        $this->assertSame('Periode 2026-03-01 s/d 2026-03-31', $raw[0][0]);
        $this->assertEquals(1, $this->sheetFrom($response)['TOTAL']['Total Kunjungan']);
    }

    public function test_export_is_scoped_for_admin_final_and_blocked_for_sales(): void
    {
        $mine = $this->kantor('MINE', 'Cabang Saya', 'AREA SATU', 'CLUSTER A');
        $other = $this->kantor('OTHER', 'Cabang Lain', 'AREA DUA', 'CLUSTER B');
        $sales = $this->salesFor($mine, $other);
        $this->visit($mine, $sales, Kunjungan::HASIL_CLOSING, '2026-03-05');
        $this->visit($other, $sales, Kunjungan::HASIL_CLOSING, '2026-03-05');

        $unit = Unit::firstOrCreate(['nama' => 'SALES'], ['is_active' => true]);
        $adminFinal = User::factory()->adminFinal()->create(['force_password_change' => false, 'unit_id' => $unit->id]);
        $adminFinal->kantor()->attach($mine->id);

        $sheet = $this->sheetFrom(
            $this->actingAs($adminFinal)->get('/laporan/summary-kunjungan/export?dari=2026-03-01&sampai=2026-03-31')
        );
        $this->assertArrayHasKey('Cabang Saya', $sheet);
        $this->assertArrayNotHasKey('Cabang Lain', $sheet);

        // A sales holding two Cabang gets the active-kantor chooser first, so
        // the role check is asserted on a single-Cabang sales instead.
        $this->actingAs($this->salesFor($mine))
            ->get('/laporan/summary-kunjungan/export')
            ->assertForbidden();
    }
}
