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
use Tests\TestCase;

class RekapSalesTest extends TestCase
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

    private function poi(Kantor $kantor, array $overrides = []): Poi
    {
        return PoiFactory::new()->create(array_merge(['kantor_id' => $kantor->id], $overrides));
    }

    private function salesUser(Kantor $kantor, Unit $unit, array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'force_password_change' => false,
            'role' => User::ROLE_SALES,
            'unit_id' => $unit->id,
        ], $overrides));
        $user->kantor()->attach($kantor->id);

        return $user;
    }

    public function test_sales_is_forbidden(): void
    {
        $sales = User::factory()->create(['force_password_change' => false]);

        $this->actingAs($sales)->get('/laporan/rekap-sales')->assertForbidden();
    }

    public function test_kunjungan_tab_only_lists_users_with_at_least_one_visit_in_range(): void
    {
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $unit = Unit::create(['nama' => 'BTRM', 'is_active' => true]);

        $withVisit = $this->salesUser($kantor, $unit, ['nama_lengkap' => 'Sudah Visit']);
        $withoutVisit = $this->salesUser($kantor, $unit, ['nama_lengkap' => 'Belum Visit']);

        $poi = $this->poi($kantor);
        Kunjungan::create([
            'poi_id' => $poi->id, 'sales_id' => $withVisit->id,
            'tanggal_kunjungan' => now()->toDateString(), 'hasil' => Kunjungan::HASIL_CLOSING,
        ]);

        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $response = $this->actingAs($admin)->get('/laporan/rekap-sales?mode=kunjungan&kantor='.$kantor->id);

        $response->assertOk();
        $rows = $response->viewData('kunjunganRows');
        $this->assertCount(1, $rows);
        $this->assertSame('Sudah Visit', $rows->first()->nama_lengkap);
        $this->assertSame(1, $rows->first()->total_visit);
        $this->assertSame(1, $rows->first()->total_closing);
    }

    public function test_tidak_kunjungan_tab_only_lists_users_with_zero_visits_in_range(): void
    {
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $unit = Unit::create(['nama' => 'BTRM', 'is_active' => true]);

        $withVisit = $this->salesUser($kantor, $unit, ['nama_lengkap' => 'Sudah Visit']);
        $withoutVisit = $this->salesUser($kantor, $unit, ['nama_lengkap' => 'Belum Visit']);

        $poi = $this->poi($kantor);
        Kunjungan::create([
            'poi_id' => $poi->id, 'sales_id' => $withVisit->id,
            'tanggal_kunjungan' => now()->toDateString(), 'hasil' => Kunjungan::HASIL_BERMINAT,
        ]);

        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $response = $this->actingAs($admin)->get('/laporan/rekap-sales?mode=tidak&kantor='.$kantor->id);

        $response->assertOk();
        $rows = $response->viewData('tidakRows');
        $this->assertCount(1, $rows);
        $this->assertSame('Belum Visit', $rows->first()->nama_lengkap);
    }

    public function test_inactive_unit_is_excluded_from_both_tabs_and_filter_options(): void
    {
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $activeUnit = Unit::create(['nama' => 'BTRM', 'is_active' => true]);
        $inactiveUnit = Unit::create(['nama' => 'RETIRED', 'is_active' => false]);

        $activeUser = $this->salesUser($kantor, $activeUnit, ['nama_lengkap' => 'Unit Aktif']);
        $inactiveUnitUser = $this->salesUser($kantor, $inactiveUnit, ['nama_lengkap' => 'Unit Nonaktif']);

        $admin = User::factory()->admin()->create(['force_password_change' => false]);

        // Tidak-kunjungan tab: both users have zero visits, but only the active-unit one
        // should surface — the inactive unit's user must not appear at all.
        $response = $this->actingAs($admin)->get('/laporan/rekap-sales?mode=tidak&kantor='.$kantor->id);
        $names = $response->viewData('tidakRows')->pluck('nama_lengkap');
        $this->assertTrue($names->contains('Unit Aktif'));
        $this->assertFalse($names->contains('Unit Nonaktif'));

        $unitOptions = $response->viewData('unitOptions')->pluck('nama');
        $this->assertTrue($unitOptions->contains('BTRM'));
        $this->assertFalse($unitOptions->contains('RETIRED'));
    }

    public function test_admin_final_forged_kantor_filter_falls_back_to_own_scope(): void
    {
        $mine = Kantor::create(['kode' => 'MINE', 'nama' => 'Kantor Saya']);
        $other = Kantor::create(['kode' => 'OTHER', 'nama' => 'Kantor Lain']);
        $unit = Unit::create(['nama' => 'BTRM', 'is_active' => true]);

        $adminFinal = User::factory()->adminFinal()->create(['force_password_change' => false]);
        $adminFinal->kantor()->attach($mine->id);

        // A sales user in the OTHER kantor with zero visits must not leak into this
        // admin_final's "tidak kunjungan" list just because they forged ?kantor=.
        $this->salesUser($other, $unit, ['nama_lengkap' => 'Orang Lain']);

        $response = $this->actingAs($adminFinal)->get('/laporan/rekap-sales?mode=tidak&kantor='.$other->id);

        $response->assertOk();
        $names = $response->viewData('tidakRows')->pluck('nama_lengkap');
        $this->assertFalse($names->contains('Orang Lain'));
    }

    public function test_unit_filter_narrows_results(): void
    {
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $unitA = Unit::create(['nama' => 'BTRM', 'is_active' => true]);
        $unitB = Unit::create(['nama' => 'BBO', 'is_active' => true]);

        $this->salesUser($kantor, $unitA, ['nama_lengkap' => 'User BTRM']);
        $this->salesUser($kantor, $unitB, ['nama_lengkap' => 'User BBO']);

        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $response = $this->actingAs($admin)->get('/laporan/rekap-sales?mode=tidak&kantor='.$kantor->id.'&unit='.$unitA->id);

        $response->assertOk();
        $names = $response->viewData('tidakRows')->pluck('nama_lengkap');
        $this->assertTrue($names->contains('User BTRM'));
        $this->assertFalse($names->contains('User BBO'));
    }

    // ---------------- Multi-select kantor filter (2026-07-22) ----------------

    public function test_multi_select_kantor_filter_narrows_scope_to_only_selected_kantor(): void
    {
        $kantorA = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $kantorB = Kantor::create(['kode' => 'B', 'nama' => 'Kantor B']);
        $kantorC = Kantor::create(['kode' => 'C', 'nama' => 'Kantor C']);
        $unit = Unit::create(['nama' => 'BTRM', 'is_active' => true]);

        $this->salesUser($kantorA, $unit, ['nama_lengkap' => 'Orang A']);
        $this->salesUser($kantorB, $unit, ['nama_lengkap' => 'Orang B']);
        $this->salesUser($kantorC, $unit, ['nama_lengkap' => 'Orang C']);

        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $response = $this->actingAs($admin)->get(
            '/laporan/rekap-sales?mode=tidak&kantor[]='.$kantorA->id.'&kantor[]='.$kantorB->id
        );

        $response->assertOk();

        $names = $response->viewData('tidakRows')->pluck('nama_lengkap');
        $this->assertTrue($names->contains('Orang A'));
        $this->assertTrue($names->contains('Orang B'));
        $this->assertFalse($names->contains('Orang C'));

        // The per-kantor histogram must only carry bars for the selected
        // kantor, not every kantor in the admin's scope (the "numpuk" bug).
        $histogram = $response->viewData('histogram');
        $this->assertEqualsCanonicalizing(['Kantor A', 'Kantor B'], $histogram['labels']);

        $selectedIds = $response->viewData('selectedKantorIds');
        $this->assertEqualsCanonicalizing([$kantorA->id, $kantorB->id], $selectedIds);
    }

    public function test_selecting_zero_kantor_defaults_to_full_scope_like_before(): void
    {
        $kantorA = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $kantorB = Kantor::create(['kode' => 'B', 'nama' => 'Kantor B']);
        $unit = Unit::create(['nama' => 'BTRM', 'is_active' => true]);

        $this->salesUser($kantorA, $unit, ['nama_lengkap' => 'Orang A']);
        $this->salesUser($kantorB, $unit, ['nama_lengkap' => 'Orang B']);

        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $response = $this->actingAs($admin)->get('/laporan/rekap-sales?mode=tidak');

        $response->assertOk();
        $names = $response->viewData('tidakRows')->pluck('nama_lengkap');
        $this->assertTrue($names->contains('Orang A'));
        $this->assertTrue($names->contains('Orang B'));
        $this->assertSame([], $response->viewData('selectedKantorIds'));
    }

    public function test_admin_final_forged_kantor_ids_via_multi_select_fall_back_to_own_scope(): void
    {
        $mine = Kantor::create(['kode' => 'MINE', 'nama' => 'Kantor Saya']);
        $other = Kantor::create(['kode' => 'OTHER', 'nama' => 'Kantor Lain']);
        $unit = Unit::create(['nama' => 'BTRM', 'is_active' => true]);

        $adminFinal = User::factory()->adminFinal()->create(['force_password_change' => false]);
        $adminFinal->kantor()->attach($mine->id);

        $this->salesUser($other, $unit, ['nama_lengkap' => 'Orang Lain']);
        $mineUser = $this->salesUser($mine, $unit, ['nama_lengkap' => 'Orang Saya']);

        $response = $this->actingAs($adminFinal)->get(
            '/laporan/rekap-sales?mode=tidak&kantor[]='.$other->id
        );

        $response->assertOk();
        $names = $response->viewData('tidakRows')->pluck('nama_lengkap');
        $this->assertFalse($names->contains('Orang Lain'));
        $this->assertTrue($names->contains('Orang Saya'));
    }

    // ---------------- Tidak-kunjungan summary (per kantor, per unit) ----------------

    public function test_tidak_kunjungan_summary_groups_by_kantor_then_unit(): void
    {
        $kantorA = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $kantorB = Kantor::create(['kode' => 'B', 'nama' => 'Kantor B']);
        $unitConsumer = Unit::create(['nama' => 'Consumer', 'is_active' => true]);
        $unitMikro = Unit::create(['nama' => 'Mikro', 'is_active' => true]);

        // Kantor A: 2x Consumer (zero visit), 1x Mikro (zero visit), 1x Consumer WITH a visit (must not count).
        $this->salesUser($kantorA, $unitConsumer, ['nama_lengkap' => 'A Consumer 1']);
        $this->salesUser($kantorA, $unitConsumer, ['nama_lengkap' => 'A Consumer 2']);
        $this->salesUser($kantorA, $unitMikro, ['nama_lengkap' => 'A Mikro 1']);
        $sudahVisit = $this->salesUser($kantorA, $unitConsumer, ['nama_lengkap' => 'A Consumer Sudah Visit']);
        $poi = $this->poi($kantorA);
        Kunjungan::create([
            'poi_id' => $poi->id, 'sales_id' => $sudahVisit->id,
            'tanggal_kunjungan' => now()->toDateString(), 'hasil' => Kunjungan::HASIL_BERMINAT,
        ]);

        // Kantor B: 1x Consumer (zero visit).
        $this->salesUser($kantorB, $unitConsumer, ['nama_lengkap' => 'B Consumer 1']);

        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $response = $this->actingAs($admin)->get('/laporan/rekap-sales?mode=tidak');

        $response->assertOk();
        $summary = $response->viewData('tidakSummary')->keyBy(fn ($row) => $row['kantor']->nama);

        $this->assertSame(3, $summary['Kantor A']['total']);
        $unitsA = $summary['Kantor A']['units']->keyBy('nama');
        $this->assertSame(2, $unitsA['Consumer']['jumlah']);
        $this->assertSame(1, $unitsA['Mikro']['jumlah']);

        $this->assertSame(1, $summary['Kantor B']['total']);
        $unitsB = $summary['Kantor B']['units']->keyBy('nama');
        $this->assertSame(1, $unitsB['Consumer']['jumlah']);
    }

    public function test_kunjungan_summary_groups_by_kantor_then_unit_summing_visit_and_closing(): void
    {
        $kantorA = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $kantorB = Kantor::create(['kode' => 'B', 'nama' => 'Kantor B']);
        $unitConsumer = Unit::create(['nama' => 'Consumer', 'is_active' => true]);
        $unitMikro = Unit::create(['nama' => 'Mikro', 'is_active' => true]);

        // Kantor A / Consumer: 2 sales, 2 visits total, 1 closing.
        $a1 = $this->salesUser($kantorA, $unitConsumer, ['nama_lengkap' => 'A Consumer 1']);
        $a2 = $this->salesUser($kantorA, $unitConsumer, ['nama_lengkap' => 'A Consumer 2']);
        // Kantor A / Mikro: 1 sales, 1 visit, 0 closing.
        $a3 = $this->salesUser($kantorA, $unitMikro, ['nama_lengkap' => 'A Mikro 1']);
        // Kantor B / Consumer: 1 sales, 1 visit, 1 closing.
        $b1 = $this->salesUser($kantorB, $unitConsumer, ['nama_lengkap' => 'B Consumer 1']);

        $poiA = $this->poi($kantorA);
        $poiB = $this->poi($kantorB);
        Kunjungan::create(['poi_id' => $poiA->id, 'sales_id' => $a1->id, 'tanggal_kunjungan' => now()->toDateString(), 'hasil' => Kunjungan::HASIL_CLOSING]);
        Kunjungan::create(['poi_id' => $poiA->id, 'sales_id' => $a2->id, 'tanggal_kunjungan' => now()->toDateString(), 'hasil' => Kunjungan::HASIL_BERMINAT]);
        Kunjungan::create(['poi_id' => $poiA->id, 'sales_id' => $a3->id, 'tanggal_kunjungan' => now()->toDateString(), 'hasil' => Kunjungan::HASIL_BERMINAT]);
        Kunjungan::create(['poi_id' => $poiB->id, 'sales_id' => $b1->id, 'tanggal_kunjungan' => now()->toDateString(), 'hasil' => Kunjungan::HASIL_CLOSING]);

        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $response = $this->actingAs($admin)->get('/laporan/rekap-sales?mode=kunjungan');

        $response->assertOk();
        $summary = $response->viewData('kunjunganSummary')->keyBy(fn ($row) => $row['kantor']->nama);

        $this->assertSame(3, $summary['Kantor A']['total']);
        $unitsA = $summary['Kantor A']['units']->keyBy('nama');
        $this->assertSame(2, $unitsA['Consumer']['visit']);
        $this->assertSame(1, $unitsA['Consumer']['closing']);
        $this->assertSame(1, $unitsA['Mikro']['visit']);
        $this->assertSame(0, $unitsA['Mikro']['closing']);

        $this->assertSame(1, $summary['Kantor B']['total']);
        $unitsB = $summary['Kantor B']['units']->keyBy('nama');
        $this->assertSame(1, $unitsB['Consumer']['visit']);
        $this->assertSame(1, $unitsB['Consumer']['closing']);
    }

    public function test_kunjungan_and_tidak_summary_are_mutually_exclusive_by_active_tab(): void
    {
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $unit = Unit::create(['nama' => 'BTRM', 'is_active' => true]);

        $sudahVisit = $this->salesUser($kantor, $unit, ['nama_lengkap' => 'Sudah Visit']);
        $this->salesUser($kantor, $unit, ['nama_lengkap' => 'Belum Visit']);
        $poi = $this->poi($kantor);
        Kunjungan::create([
            'poi_id' => $poi->id, 'sales_id' => $sudahVisit->id,
            'tanggal_kunjungan' => now()->toDateString(), 'hasil' => Kunjungan::HASIL_BERMINAT,
        ]);

        $admin = User::factory()->admin()->create(['force_password_change' => false]);

        // On the "kunjungan" tab, the kunjungan breakdown is populated and the
        // tidak breakdown is empty (not just unused — genuinely not fetched).
        $kunjunganResponse = $this->actingAs($admin)->get('/laporan/rekap-sales?mode=kunjungan&kantor[]='.$kantor->id);
        $kunjunganResponse->assertOk();
        $this->assertTrue($kunjunganResponse->viewData('kunjunganSummary')->isNotEmpty());
        $this->assertTrue($kunjunganResponse->viewData('tidakSummary')->isEmpty());
        $this->assertNull($kunjunganResponse->viewData('tidakRows'));

        // On the "tidak" tab, it's the exact reverse.
        $tidakResponse = $this->actingAs($admin)->get('/laporan/rekap-sales?mode=tidak&kantor[]='.$kantor->id);
        $tidakResponse->assertOk();
        $this->assertTrue($tidakResponse->viewData('tidakSummary')->isNotEmpty());
        $this->assertTrue($tidakResponse->viewData('kunjunganSummary')->isEmpty());
        $this->assertNull($tidakResponse->viewData('kunjunganRows'));
    }

    // ---------------- Histogram summary aggregate (2026-07-22) ----------------

    public function test_histogram_summary_aggregates_totals_across_kantor(): void
    {
        $kantorA = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $kantorB = Kantor::create(['kode' => 'B', 'nama' => 'Kantor B']);
        $unit = Unit::create(['nama' => 'BTRM', 'is_active' => true]);

        // Kantor A: 2 sales, one with a visit that closes, one with zero visits.
        $salesA1 = $this->salesUser($kantorA, $unit, ['nama_lengkap' => 'Sales A1']);
        $this->salesUser($kantorA, $unit, ['nama_lengkap' => 'Sales A2 Belum Visit']);
        $poiA = $this->poi($kantorA);
        Kunjungan::create([
            'poi_id' => $poiA->id, 'sales_id' => $salesA1->id,
            'tanggal_kunjungan' => now()->toDateString(), 'hasil' => Kunjungan::HASIL_CLOSING,
        ]);

        // Kantor B: 1 sales with a visit that does NOT close, zero belum-visit users.
        $salesB1 = $this->salesUser($kantorB, $unit, ['nama_lengkap' => 'Sales B1']);
        $poiB = $this->poi($kantorB);
        Kunjungan::create([
            'poi_id' => $poiB->id, 'sales_id' => $salesB1->id,
            'tanggal_kunjungan' => now()->toDateString(), 'hasil' => Kunjungan::HASIL_BERMINAT,
        ]);

        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $response = $this->actingAs($admin)->get(
            '/laporan/rekap-sales?mode=kunjungan&kantor[]='.$kantorA->id.'&kantor[]='.$kantorB->id
        );

        $response->assertOk();
        $summary = $response->viewData('histogram')['summary'];

        // 2 total kunjungan (1 per kantor).
        $this->assertSame(2, $summary['total_kunjungan']);
        $this->assertSame(1, $summary['total_tidak']); // only Sales A2
        // Both kantor had >=1 kunjungan in range -> both count as "aktif".
        $this->assertSame(2, $summary['kantor_aktif']);
        $this->assertSame(2, $summary['total_kantor']);
    }

    public function test_histogram_summary_kantor_aktif_is_zero_when_there_are_no_visits(): void
    {
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $unit = Unit::create(['nama' => 'BTRM', 'is_active' => true]);
        $this->salesUser($kantor, $unit, ['nama_lengkap' => 'Belum Visit']);

        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $response = $this->actingAs($admin)->get('/laporan/rekap-sales?mode=kunjungan&kantor[]='.$kantor->id);

        $response->assertOk();
        $summary = $response->viewData('histogram')['summary'];

        $this->assertSame(0, $summary['total_kunjungan']);
        $this->assertSame(0, $summary['kantor_aktif']);
        $this->assertSame(1, $summary['total_kantor']);
    }
}
