<?php

namespace Tests\Feature;

use App\Models\Kantor;
use App\Models\Kunjungan;
use App\Models\Poi;
use App\Models\User;
use Database\Factories\PoiFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // PoiObserver -> DashboardSummaryService requires the sentinel "ALL"
        // kantor row to exist (normally seeded by DatabaseSeeder).
        Kantor::firstOrCreate(
            ['kode' => Kantor::SENTINEL_ALL_KODE],
            ['nama' => 'Seluruh Kantor', 'is_active' => false],
        );
    }

    private function poi(Kantor $kantor, array $overrides = []): Poi
    {
        return PoiFactory::new()->create(array_merge(['kantor_id' => $kantor->id], $overrides));
    }

    public function test_dashboard_is_accessible_by_all_three_roles(): void
    {
        $kantor = Kantor::create(['kode' => 'K1', 'nama' => 'Kantor Satu']);

        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $this->actingAs($admin)->get('/dashboard')->assertOk();

        $adminFinal = User::factory()->adminFinal()->create(['force_password_change' => false]);
        $adminFinal->kantor()->attach($kantor->id);
        $this->actingAs($adminFinal)->get('/dashboard')->assertOk();

        $sales = User::factory()->create(['force_password_change' => false]);
        $sales->kantor()->attach($kantor->id);
        $this->actingAs($sales)->get('/dashboard')->assertOk();
    }

    public function test_stat_cards_combine_merchant_statuses_into_the_bni_bucket(): void
    {
        $kantor = Kantor::create(['kode' => 'K1', 'nama' => 'Kantor Satu']);
        $this->poi($kantor, ['status_mitra' => 'Nasabah Merchant BNI']);
        $this->poi($kantor, ['status_mitra' => 'Nasabah Non Merchant BNI']);
        $this->poi($kantor, ['status_mitra' => 'Bukan Nasabah BNI']);
        $this->poi($kantor, ['status_mitra' => 'Bukan Nasabah BNI']);

        $admin = User::factory()->admin()->create(['force_password_change' => false]);

        $response = $this->actingAs($admin)->get('/dashboard?kantor='.$kantor->id);

        $response->assertOk();
        $response->assertViewHas('totals', function ($totals) {
            return $totals['total_poi'] === 4 && $totals['total_bni'] === 2 && $totals['total_non'] === 2;
        });
    }

    public function test_admin_final_unscoped_view_aggregates_only_their_own_kantor(): void
    {
        $mine = Kantor::create(['kode' => 'MINE', 'nama' => 'Kantor Saya']);
        $other = Kantor::create(['kode' => 'OTHER', 'nama' => 'Kantor Lain']);

        $this->poi($mine, ['status_mitra' => 'Bukan Nasabah BNI']);
        $this->poi($mine, ['status_mitra' => 'Bukan Nasabah BNI']);
        // Belongs to a kantor this admin_final has nothing to do with — must not leak in.
        $this->poi($other, ['status_mitra' => 'Bukan Nasabah BNI']);

        $adminFinal = User::factory()->adminFinal()->create(['force_password_change' => false]);
        $adminFinal->kantor()->attach($mine->id);

        $response = $this->actingAs($adminFinal)->get('/dashboard');

        $response->assertOk();
        $response->assertViewHas('totals', fn ($totals) => $totals['total_poi'] === 2);
    }

    public function test_admin_final_forged_kantor_filter_outside_their_assignment_is_ignored(): void
    {
        $mine = Kantor::create(['kode' => 'MINE', 'nama' => 'Kantor Saya']);
        $other = Kantor::create(['kode' => 'OTHER', 'nama' => 'Kantor Lain']);

        $this->poi($mine);
        $this->poi($other);
        $this->poi($other);

        $adminFinal = User::factory()->adminFinal()->create(['force_password_change' => false]);
        $adminFinal->kantor()->attach($mine->id);

        // Forging ?kantor= to the other kantor's id must not narrow the query to it —
        // it should fall back to the admin_final's own aggregate (1 POI), not leak the
        // other kantor's 2 POI.
        $response = $this->actingAs($adminFinal)->get('/dashboard?kantor='.$other->id);

        $response->assertOk();
        $response->assertViewHas('totals', fn ($totals) => $totals['total_poi'] === 1);
    }

    public function test_admin_can_combine_several_kantor_at_once_for_monitoring(): void
    {
        $a = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $b = Kantor::create(['kode' => 'B', 'nama' => 'Kantor B']);
        $c = Kantor::create(['kode' => 'C', 'nama' => 'Kantor C']);

        $this->poi($a, ['status_mitra' => 'Bukan Nasabah BNI']);
        $this->poi($b, ['status_mitra' => 'Bukan Nasabah BNI']);
        // Not selected below — must not be counted in.
        $this->poi($c, ['status_mitra' => 'Bukan Nasabah BNI']);
        $this->poi($c, ['status_mitra' => 'Bukan Nasabah BNI']);

        $admin = User::factory()->admin()->create(['force_password_change' => false]);

        $response = $this->actingAs($admin)->get('/dashboard?kantor[]='.$a->id.'&kantor[]='.$b->id);

        $response->assertOk();
        $response->assertViewHas('totals', fn ($totals) => $totals['total_poi'] === 2);
        $response->assertViewHas('selectedKantorIds', fn ($ids) => $ids === [$a->id, $b->id]);
    }

    public function test_admin_final_multi_select_drops_a_forged_id_but_keeps_the_valid_ones(): void
    {
        $mine = Kantor::create(['kode' => 'MINE', 'nama' => 'Kantor Saya']);
        $alsoMine = Kantor::create(['kode' => 'MINE2', 'nama' => 'Kantor Saya 2']);
        $other = Kantor::create(['kode' => 'OTHER', 'nama' => 'Kantor Lain']);

        $this->poi($mine, ['status_mitra' => 'Bukan Nasabah BNI']);
        $this->poi($alsoMine, ['status_mitra' => 'Bukan Nasabah BNI']);
        $this->poi($other, ['status_mitra' => 'Bukan Nasabah BNI']);

        $adminFinal = User::factory()->adminFinal()->create(['force_password_change' => false]);
        $adminFinal->kantor()->attach([$mine->id, $alsoMine->id]);

        // Mixing a legitimate id with a forged one must keep the legitimate
        // selection intact, not fall back to leaking the forged kantor or
        // wiping the whole selection.
        $response = $this->actingAs($adminFinal)
            ->get('/dashboard?kantor[]='.$mine->id.'&kantor[]='.$other->id);

        $response->assertOk();
        $response->assertViewHas('totals', fn ($totals) => $totals['total_poi'] === 1);
        $response->assertViewHas('selectedKantorIds', fn ($ids) => $ids === [$mine->id]);
    }

    public function test_sales_is_locked_to_their_active_kantor_regardless_of_query_param(): void
    {
        $mine = Kantor::create(['kode' => 'MINE', 'nama' => 'Kantor Saya']);
        $other = Kantor::create(['kode' => 'OTHER', 'nama' => 'Kantor Lain']);

        $this->poi($mine);
        $this->poi($other);
        $this->poi($other);

        $sales = User::factory()->create(['force_password_change' => false]);
        $sales->kantor()->attach($mine->id);

        $response = $this->actingAs($sales)->get('/dashboard?kantor='.$other->id);

        $response->assertOk();
        $response->assertViewHas('totals', fn ($totals) => $totals['total_poi'] === 1);
    }

    public function test_area_breakdown_shares_the_same_ring1to3_denominator_across_variants(): void
    {
        $kantor = Kantor::create(['kode' => 'K1', 'nama' => 'Kantor Satu']);
        $ring1 = Poi::AREA_OPTIONS[0];
        $ring2 = Poi::AREA_OPTIONS[1];
        $ring4 = Poi::AREA_OPTIONS[3];
        // Ring 1: 2 POI (1 BNI, 1 Non); Ring 2: 1 POI (BNI); Ring 4 excluded entirely.
        $this->poi($kantor, ['area' => $ring1, 'status_mitra' => 'Nasabah Merchant BNI']);
        $this->poi($kantor, ['area' => $ring1, 'status_mitra' => 'Bukan Nasabah BNI']);
        $this->poi($kantor, ['area' => $ring2, 'status_mitra' => 'Nasabah Non Merchant BNI']);
        $this->poi($kantor, ['area' => $ring4, 'status_mitra' => 'Bukan Nasabah BNI']);

        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $response = $this->actingAs($admin)->get('/dashboard?kantor='.$kantor->id);

        $response->assertOk();
        $response->assertViewHas('area', function ($area) use ($ring1, $ring2) {
            // total_r123 = 3 (Ring 4 excluded) — every variant divides by that same 3.
            return $area['all'][$ring1]['total'] === 2
                && $area['all'][$ring1]['persen'] === 66.7
                && $area['all'][$ring2]['total'] === 1
                && $area['bni'][$ring1]['total'] === 1
                && $area['bni'][$ring1]['persen'] === 33.3
                && $area['non'][$ring1]['total'] === 1
                && $area['non'][$ring1]['persen'] === 33.3;
        });
    }

    public function test_funnel_and_produk_counts_reflect_seeded_kunjungan(): void
    {
        $kantor = Kantor::create(['kode' => 'K1', 'nama' => 'Kantor Satu']);
        $poiA = $this->poi($kantor);
        $poiB = $this->poi($kantor);
        $sales = User::factory()->create(['force_password_change' => false]);

        // A real HTML date input (KunjunganController's actual input source) always sends
        // a clean 'Y-m-d' string — using ->toDateString() here rather than a bare now()
        // (which carries the current time-of-day) matches that real shape. A bare Carbon
        // `now()` also uncovered a genuine storage quirk (see Kunjungan::casts()) where
        // the extra time component survives into the DB on SQLite, so this isn't just
        // test-data pedantry.
        $kunjunganA = Kunjungan::create([
            'poi_id' => $poiA->id, 'sales_id' => $sales->id, 'tanggal_kunjungan' => now()->toDateString(),
            'hasil' => Kunjungan::HASIL_CLOSING,
        ]);
        // produk is now a separate pivot (kunjungan_produk) — a visit can offer
        // several products at once, see Kunjungan::PRODUK_OPTIONS docblock.
        $kunjunganA->produkList()->create(['produk' => 'Tabungan']);
        Kunjungan::create([
            'poi_id' => $poiB->id, 'sales_id' => $sales->id, 'tanggal_kunjungan' => now()->toDateString(),
            'hasil' => Kunjungan::HASIL_BERMINAT,
        ]);

        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $response = $this->actingAs($admin)->get('/dashboard?kantor='.$kantor->id.'&periode=day');

        $response->assertOk();
        $funnel = $response->viewData('funnel');
        $this->assertSame(1, $funnel[Kunjungan::HASIL_CLOSING], 'closing count, full funnel: '.json_encode($funnel));
        $this->assertSame(1, $funnel[Kunjungan::HASIL_BERMINAT], 'berminat count, full funnel: '.json_encode($funnel));
        $response->assertViewHas('produk', fn ($produk) => $produk['Tabungan'] === 1);
        $response->assertViewHas('totalHasilKunjungan', fn ($total) => $total === 2);
    }

    public function test_periode_day_excludes_kunjungan_from_other_days(): void
    {
        $kantor = Kantor::create(['kode' => 'K1', 'nama' => 'Kantor Satu']);
        $poi = $this->poi($kantor);
        $sales = User::factory()->create(['force_password_change' => false]);

        Kunjungan::create([
            'poi_id' => $poi->id, 'sales_id' => $sales->id,
            'tanggal_kunjungan' => now()->subDays(10), 'hasil' => Kunjungan::HASIL_CLOSING,
        ]);

        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $response = $this->actingAs($admin)->get('/dashboard?kantor='.$kantor->id.'&periode=day');

        $response->assertOk();
        $response->assertViewHas('totalHasilKunjungan', fn ($total) => $total === 0);
        // Total POI is current stock, never period-filtered — still counts the POI itself.
        $response->assertViewHas('totals', fn ($totals) => $totals['total_poi'] === 1);
    }
}
