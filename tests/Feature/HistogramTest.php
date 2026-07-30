<?php

namespace Tests\Feature;

use App\Models\Kantor;
use App\Models\Kunjungan;
use App\Models\Poi;
use App\Models\User;
use Database\Factories\PoiFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HistogramTest extends TestCase
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

    private function poi(Kantor $kantor, array $overrides = []): Poi
    {
        return PoiFactory::new()->create(array_merge(['kantor_id' => $kantor->id], $overrides));
    }

    public function test_sales_is_forbidden(): void
    {
        $sales = User::factory()->create(['force_password_change' => false]);

        $this->actingAs($sales)->get('/histogram')->assertForbidden();
    }

    public function test_admin_and_admin_final_can_view(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $this->actingAs($admin)->get('/histogram')->assertOk();

        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $adminFinal = User::factory()->adminFinal()->create(['force_password_change' => false]);
        $adminFinal->kantor()->attach($kantor->id);
        $this->actingAs($adminFinal)->get('/histogram')->assertOk();
    }

    public function test_admin_final_with_exactly_one_kantor_is_auto_locked(): void
    {
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $adminFinal = User::factory()->adminFinal()->create(['force_password_change' => false]);
        $adminFinal->kantor()->attach($kantor->id);

        $response = $this->actingAs($adminFinal)->get('/histogram');

        $response->assertOk();
        $response->assertViewHas('kantorLocked', true);
        $response->assertViewHas('selectedKantorId', $kantor->id);
    }

    public function test_admin_final_with_multiple_kantor_forged_filter_falls_back_to_own_scope(): void
    {
        $mine = Kantor::create(['kode' => 'MINE', 'nama' => 'Kantor Saya']);
        $mine2 = Kantor::create(['kode' => 'MINE2', 'nama' => 'Kantor Saya 2']);
        $other = Kantor::create(['kode' => 'OTHER', 'nama' => 'Kantor Lain']);
        $adminFinal = User::factory()->adminFinal()->create(['force_password_change' => false]);
        $adminFinal->kantor()->attach([$mine->id, $mine2->id]);

        $sales = User::factory()->create(['force_password_change' => false]);
        $poiOther = $this->poi($other);
        Kunjungan::create([
            'poi_id' => $poiOther->id, 'sales_id' => $sales->id,
            'tanggal_kunjungan' => now()->toDateString(), 'hasil' => Kunjungan::HASIL_CLOSING,
        ]);

        $response = $this->actingAs($adminFinal)->get('/histogram?kantor='.$other->id);

        $response->assertOk();
        $response->assertViewHas('selectedKantorId', null);
        // The other kantor's closing must not surface anywhere on the page.
        $response->assertViewHas('histogram', fn ($h) => array_sum($h['closing']) === 0);
    }

    public function test_area_filter_narrows_the_histogram_scope(): void
    {
        $jakarta = Kantor::create(['kode' => 'A', 'nama' => 'Cabang Jakarta', 'area' => 'Area Jakarta']);
        $bandung = Kantor::create(['kode' => 'B', 'nama' => 'Cabang Bandung', 'area' => 'Area Jabar']);
        $sales = User::factory()->create(['force_password_change' => false]);

        Kunjungan::create([
            'poi_id' => $this->poi($jakarta)->id, 'sales_id' => $sales->id,
            'tanggal_kunjungan' => now()->toDateString(), 'hasil' => Kunjungan::HASIL_CLOSING,
        ]);
        Kunjungan::create([
            'poi_id' => $this->poi($bandung)->id, 'sales_id' => $sales->id,
            'tanggal_kunjungan' => now()->toDateString(), 'hasil' => Kunjungan::HASIL_CLOSING,
        ]);

        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $response = $this->actingAs($admin)->get('/histogram?area='.urlencode('Area Jakarta'));

        $response->assertOk();
        $response->assertViewHas('histogram', fn ($h) => $h['closing'] === [1]);
    }

    public function test_histogram_counts_distinct_poi_per_day_not_raw_visits(): void
    {
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $poi = $this->poi($kantor);
        $sales = User::factory()->create(['force_password_change' => false]);

        // Two closing visits to the SAME poi on the SAME day must count once.
        Kunjungan::create([
            'poi_id' => $poi->id, 'sales_id' => $sales->id,
            'tanggal_kunjungan' => now()->toDateString(), 'hasil' => Kunjungan::HASIL_CLOSING,
        ]);
        Kunjungan::create([
            'poi_id' => $poi->id, 'sales_id' => $sales->id,
            'tanggal_kunjungan' => now()->toDateString(), 'hasil' => Kunjungan::HASIL_CLOSING,
        ]);

        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $response = $this->actingAs($admin)->get('/histogram?kantor='.$kantor->id);

        $response->assertOk();
        $response->assertViewHas('histogram', fn ($h) => $h['closing'] === [1]);
    }

    public function test_produk_histograms_split_ditawarkan_from_closing(): void
    {
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $poiA = $this->poi($kantor);
        $poiB = $this->poi($kantor);
        $sales = User::factory()->create(['force_password_change' => false]);

        $closingVisit = Kunjungan::create([
            'poi_id' => $poiA->id, 'sales_id' => $sales->id,
            'tanggal_kunjungan' => now()->toDateString(), 'hasil' => Kunjungan::HASIL_CLOSING,
        ]);
        $closingVisit->produkList()->create(['produk' => 'Tabungan']);
        $closingVisit->produkList()->create(['produk' => 'KUR']);

        // Offered but the visit didn't close: counts as Ditawarkan only.
        $openVisit = Kunjungan::create([
            'poi_id' => $poiB->id, 'sales_id' => $sales->id,
            'tanggal_kunjungan' => now()->toDateString(), 'hasil' => Kunjungan::HASIL_BERMINAT,
        ]);
        $openVisit->produkList()->create(['produk' => 'Tabungan']);
        $openVisit->produkList()->create(['produk' => 'QRIS']);

        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $response = $this->actingAs($admin)->get('/histogram?kantor='.$kantor->id);

        $response->assertOk();
        $grup = collect($response->viewData('produkGrup'))->keyBy('judul');

        $this->assertSame(['DPK', 'LOAN', 'Transaksional & Lainnya'], $grup->keys()->all());

        $dpk = $grup['DPK'];
        $tabungan = array_search('Tabungan', $dpk['labels'], true);
        $this->assertSame(2, $dpk['ditawarkan'][$tabungan]);
        $this->assertSame(1, $dpk['closing'][$tabungan]);
        $this->assertSame(50.0, $dpk['closing_rate'], 'Satu dari dua penawaran Tabungan jadi closing.');

        $loan = $grup['LOAN'];
        $kur = array_search('KUR', $loan['labels'], true);
        $this->assertSame(1, $loan['ditawarkan'][$kur]);
        $this->assertSame(1, $loan['closing'][$kur]);

        $trx = $grup['Transaksional & Lainnya'];
        $qris = array_search('QRIS', $trx['labels'], true);
        $this->assertSame(1, $trx['ditawarkan'][$qris]);
        $this->assertSame(0, $trx['closing'][$qris], 'QRIS ditawarkan tapi kunjungannya belum closing.');
    }

    /**
     * The three groups must cover Kunjungan::PRODUK_OPTIONS exactly — a produk
     * added to the model but not placed in a group would silently vanish from
     * the page.
     */
    public function test_the_three_groups_partition_every_produk_exactly_once(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $response = $this->actingAs($admin)->get('/histogram');

        $response->assertOk();
        $rendered = collect($response->viewData('produkGrup'))->pluck('labels')->flatten();

        $this->assertCount(count(Kunjungan::PRODUK_OPTIONS), $rendered);
        $this->assertCount($rendered->count(), $rendered->unique(), 'Ada produk yang muncul di dua grup.');
    }
}
