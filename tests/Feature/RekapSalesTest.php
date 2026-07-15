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
}
