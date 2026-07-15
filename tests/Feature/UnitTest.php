<?php

namespace Tests\Feature;

use App\Models\Kantor;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\RegistersUserRoutes;
use Tests\TestCase;

/**
 * routes/unit.php is required by routes/web.php in the real app; registered
 * directly here (same pattern as other module test suites) so these tests
 * exercise the real controller/middleware stack without depending on
 * whatever else routes/web.php wires up.
 */
class UnitTest extends TestCase
{
    use RefreshDatabase;
    use RegistersUserRoutes;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth', 'force.password.change', 'active.kantor'])
            ->group(base_path('routes/unit.php'));
        Route::getRoutes()->refreshNameLookups();

        Kantor::firstOrCreate(
            ['kode' => Kantor::SENTINEL_ALL_KODE],
            ['nama' => 'Seluruh Kantor', 'is_active' => false],
        );
    }

    public function test_only_admin_can_access_unit_routes(): void
    {
        $sales = User::factory()->create(['force_password_change' => false]);
        $adminFinal = User::factory()->adminFinal()->create(['force_password_change' => false]);

        $this->actingAs($sales)->get('/unit')->assertForbidden();
        $this->actingAs($adminFinal)->get('/unit')->assertForbidden();
    }

    public function test_admin_can_create_a_unit(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);

        $response = $this->actingAs($admin)->post('/unit', ['nama' => 'BRANCH MANAGER']);

        $response->assertRedirect();
        $this->assertDatabaseHas('unit', ['nama' => 'BRANCH MANAGER', 'is_active' => 1]);
    }

    public function test_unit_name_must_be_unique(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        Unit::create(['nama' => 'STAFF', 'is_active' => true]);

        $response = $this->actingAs($admin)->post('/unit', ['nama' => 'STAFF']);

        $response->assertSessionHasErrors('nama');
        $this->assertSame(1, Unit::where('nama', 'STAFF')->count());
    }

    public function test_admin_can_rename_a_unit(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $unit = Unit::create(['nama' => 'STAFF', 'is_active' => true]);

        $response = $this->actingAs($admin)->put("/unit/{$unit->id}", ['nama' => 'STAFF LAMA']);

        $response->assertRedirect();
        $this->assertSame('STAFF LAMA', $unit->fresh()->nama);
    }

    public function test_admin_can_toggle_unit_active_status(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $unit = Unit::create(['nama' => 'STAFF', 'is_active' => true]);

        $this->actingAs($admin)->post("/unit/{$unit->id}/toggle-active");
        $this->assertFalse($unit->fresh()->is_active);

        $this->actingAs($admin)->post("/unit/{$unit->id}/toggle-active");
        $this->assertTrue($unit->fresh()->is_active);
    }

    public function test_inactive_units_are_excluded_from_the_user_form_picker(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        Unit::create(['nama' => 'AKTIF', 'is_active' => true]);
        Unit::create(['nama' => 'NONAKTIF', 'is_active' => false]);

        $this->registerUserRoutes();

        $response = $this->actingAs($admin)->get('/user/create');

        $response->assertOk()->assertSee('AKTIF')->assertDontSee('NONAKTIF');
    }
}
