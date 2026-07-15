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
        $response->assertViewHas('detail', fn ($detail) => $detail->total() === 0);
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

    public function test_produk_totals_and_closing_rate_are_consistent_with_pies(): void
    {
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $poiA = $this->poi($kantor);
        $poiB = $this->poi($kantor);
        $sales = User::factory()->create(['force_password_change' => false]);

        $k1 = Kunjungan::create([
            'poi_id' => $poiA->id, 'sales_id' => $sales->id,
            'tanggal_kunjungan' => now()->toDateString(), 'hasil' => Kunjungan::HASIL_CLOSING,
        ]);
        $k1->produkList()->create(['produk' => 'Tabungan']);

        $k2 = Kunjungan::create([
            'poi_id' => $poiB->id, 'sales_id' => $sales->id,
            'tanggal_kunjungan' => now()->toDateString(), 'hasil' => Kunjungan::HASIL_BERMINAT,
        ]);
        $k2->produkList()->create(['produk' => 'Tabungan']);

        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $response = $this->actingAs($admin)->get('/histogram?kantor='.$kantor->id);

        $response->assertOk();
        $response->assertViewHas('produkAll', fn ($p) => $p['Tabungan'] === 2);
        $response->assertViewHas('produkClosing', fn ($p) => $p['Tabungan'] === 1);
        // 1 closing mention / 2 total mentions = 50%.
        $response->assertViewHas('closingRate', 50.0);
    }

    public function test_detail_table_only_shows_closing_and_is_paginated(): void
    {
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $sales = User::factory()->create(['force_password_change' => false]);

        for ($i = 0; $i < 20; $i++) {
            $poi = $this->poi($kantor);
            Kunjungan::create([
                'poi_id' => $poi->id, 'sales_id' => $sales->id,
                'tanggal_kunjungan' => now()->toDateString(), 'hasil' => Kunjungan::HASIL_CLOSING,
            ]);
        }
        $poiNonClosing = $this->poi($kantor);
        Kunjungan::create([
            'poi_id' => $poiNonClosing->id, 'sales_id' => $sales->id,
            'tanggal_kunjungan' => now()->toDateString(), 'hasil' => Kunjungan::HASIL_BERMINAT,
        ]);

        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $response = $this->actingAs($admin)->get('/histogram?kantor='.$kantor->id);

        $response->assertOk();
        $paginator = $response->viewData('detail');
        $this->assertSame(20, $paginator->total());
        $this->assertLessThan(20, $paginator->count());
        $this->assertTrue($paginator->hasMorePages());
    }
}
