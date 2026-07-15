<?php

namespace Tests\Feature;

use App\Models\Kantor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_view_login_page(): void
    {
        $this->get('/login')->assertOk()->assertSee('PROMIS');
    }

    public function test_guest_is_redirected_to_login_from_protected_pages(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_login_with_correct_credentials_succeeds(): void
    {
        $user = User::factory()->create(['npp' => '1234567', 'password' => 'rahasia123', 'force_password_change' => false]);

        $response = $this->post('/login', ['npp' => '1234567', 'password' => 'rahasia123']);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_with_wrong_password_fails(): void
    {
        User::factory()->create(['npp' => '1234567', 'password' => 'rahasia123']);

        $response = $this->post('/login', ['npp' => '1234567', 'password' => 'salah']);

        $response->assertSessionHasErrors('npp');
        $this->assertGuest();
    }

    public function test_login_locks_out_after_five_failed_attempts(): void
    {
        User::factory()->create(['npp' => '1234567', 'password' => 'rahasia123']);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['npp' => '1234567', 'password' => 'salah']);
        }

        // Correct password on the 6th attempt should still be rejected — locked out.
        $response = $this->post('/login', ['npp' => '1234567', 'password' => 'rahasia123']);

        $response->assertSessionHasErrors('npp');
        $this->assertGuest();
    }

    public function test_force_password_change_cannot_be_skipped(): void
    {
        $user = User::factory()->create([
            'npp' => '1234567',
            'password' => 'rahasia123',
            'force_password_change' => true,
        ]);

        $this->actingAs($user)->get('/dashboard')->assertRedirect('/ganti-password');
    }

    public function test_password_change_clears_the_force_flag_and_unlocks_dashboard(): void
    {
        $user = User::factory()->create([
            'npp' => '1234567',
            'password' => 'rahasia123',
            'force_password_change' => true,
        ]);
        // Single-kantor users are auto-selected by EnsureActiveKantor — isolates this
        // test from the separate multi-kantor pilih-kantor flow covered below.
        $user->kantor()->attach(Kantor::create(['kode' => 'X', 'nama' => 'Kantor X'])->id);

        $response = $this->actingAs($user)->post('/ganti-password', [
            'password_lama' => 'rahasia123',
            'password_baru' => 'passwordBaru123',
            'password_baru_confirmation' => 'passwordBaru123',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertFalse($user->fresh()->force_password_change);

        $this->actingAs($user->fresh())->get('/dashboard')->assertOk();
    }

    public function test_admin_bypasses_active_kantor_selection(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);

        $this->actingAs($admin)->get('/dashboard')->assertOk();
    }

    public function test_sales_with_multiple_kantor_is_redirected_to_pilih_kantor(): void
    {
        $user = User::factory()->create(['force_password_change' => false, 'role' => User::ROLE_SALES]);
        $kantorA = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $kantorB = Kantor::create(['kode' => 'B', 'nama' => 'Kantor B']);
        $user->kantor()->attach([$kantorA->id, $kantorB->id]);

        $this->actingAs($user)->get('/dashboard')->assertRedirect('/pilih-kantor');
    }

    /**
     * Confirmed against the real v1 dashboard.php: only `sales` is forced through
     * pilih-kantor. admin_final browses an aggregate view across all their kantor
     * by default, no session-locked selection required.
     */
    public function test_admin_final_with_multiple_kantor_is_not_redirected_to_pilih_kantor(): void
    {
        $user = User::factory()->adminFinal()->create(['force_password_change' => false]);
        $kantorA = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $kantorB = Kantor::create(['kode' => 'B', 'nama' => 'Kantor B']);
        $user->kantor()->attach([$kantorA->id, $kantorB->id]);

        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    public function test_choosing_a_kantor_not_owned_by_the_user_is_rejected(): void
    {
        $user = User::factory()->create(['force_password_change' => false]);
        $kantorMine = Kantor::create(['kode' => 'MINE', 'nama' => 'Kantor Saya']);
        $kantorOther = Kantor::create(['kode' => 'OTHER', 'nama' => 'Kantor Orang Lain']);
        $user->kantor()->attach([$kantorMine->id, $kantorOther->id]);

        $notMine = Kantor::create(['kode' => 'NOTMINE', 'nama' => 'Bukan Kantor Saya']);

        $response = $this->actingAs($user)->post('/pilih-kantor', ['kantor_id' => $notMine->id]);

        $response->assertSessionHasErrors('kantor_id');
    }

    public function test_choosing_an_owned_kantor_unlocks_the_dashboard(): void
    {
        $user = User::factory()->create(['force_password_change' => false]);
        $kantorA = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $kantorB = Kantor::create(['kode' => 'B', 'nama' => 'Kantor B']);
        $user->kantor()->attach([$kantorA->id, $kantorB->id]);

        $response = $this->actingAs($user)->post('/pilih-kantor', ['kantor_id' => $kantorA->id]);

        $response->assertRedirect('/dashboard');
        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    public function test_logout_invalidates_the_session(): void
    {
        $user = User::factory()->create(['force_password_change' => false]);

        $this->actingAs($user)->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    protected function tearDown(): void
    {
        RateLimiter::clear('1234567|127.0.0.1');
        parent::tearDown();
    }
}
