<?php

namespace Tests\Feature;

use App\Models\Kantor;
use App\Models\Kunjungan;
use App\Models\PoiReopenLog;
use App\Models\Unit;
use App\Models\User;
use Database\Factories\PoiFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\RegistersUserRoutes;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;
    use RegistersUserRoutes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerUserRoutes();

        // Sentinel "ALL" kantor row, normally seeded by DatabaseSeeder — not
        // strictly required by this module but kept consistent with the
        // other feature test suites (EnsureActiveKantor/PoiObserver touch it).
        Kantor::firstOrCreate(
            ['kode' => Kantor::SENTINEL_ALL_KODE],
            ['nama' => 'Seluruh Kantor', 'is_active' => false],
        );
    }

    // ---------------- Role gating: admin only, every route ----------------

    public function test_admin_final_gets_403_on_every_user_management_route(): void
    {
        $adminFinal = User::factory()->adminFinal()->create(['force_password_change' => false]);
        $kantor = Kantor::create(['kode' => 'X', 'nama' => 'Kantor X']);
        $target = User::factory()->create(['force_password_change' => false]);

        $this->actingAs($adminFinal)->get('/user')->assertForbidden();
        $this->actingAs($adminFinal)->get('/user/create')->assertForbidden();
        $this->actingAs($adminFinal)->post('/user', [])->assertForbidden();
        $this->actingAs($adminFinal)->get("/user/{$target->id}/edit")->assertForbidden();
        $this->actingAs($adminFinal)->put("/user/{$target->id}", [])->assertForbidden();
        $this->actingAs($adminFinal)->post("/user/{$target->id}/toggle-active")->assertForbidden();
        $this->actingAs($adminFinal)->post("/user/{$target->id}/reset-password")->assertForbidden();
        $this->actingAs($adminFinal)->delete("/user/{$target->id}")->assertForbidden();
        $this->actingAs($adminFinal)->get('/user-import')->assertForbidden();
        $this->actingAs($adminFinal)->post('/user-import', [])->assertForbidden();
        $this->actingAs($adminFinal)->get('/user-import/template')->assertForbidden();
    }

    public function test_sales_gets_403_on_every_user_management_route(): void
    {
        $sales = User::factory()->create(['force_password_change' => false, 'role' => User::ROLE_SALES]);
        $kantor = Kantor::create(['kode' => 'X', 'nama' => 'Kantor X']);
        $sales->kantor()->attach($kantor->id);
        $target = User::factory()->create(['force_password_change' => false]);

        $this->actingAs($sales)->get('/user')->assertForbidden();
        $this->actingAs($sales)->get('/user/create')->assertForbidden();
        $this->actingAs($sales)->post('/user', [])->assertForbidden();
        $this->actingAs($sales)->get("/user/{$target->id}/edit")->assertForbidden();
        $this->actingAs($sales)->put("/user/{$target->id}", [])->assertForbidden();
        $this->actingAs($sales)->post("/user/{$target->id}/toggle-active")->assertForbidden();
        $this->actingAs($sales)->post("/user/{$target->id}/reset-password")->assertForbidden();
        $this->actingAs($sales)->delete("/user/{$target->id}")->assertForbidden();
        $this->actingAs($sales)->get('/user-import')->assertForbidden();
        $this->actingAs($sales)->post('/user-import', [])->assertForbidden();
        $this->actingAs($sales)->get('/user-import/template')->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/user')->assertRedirect('/login');
    }

    // ---------------- Index / listing ----------------

    public function test_admin_can_list_users_with_pagination(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);

        for ($i = 0; $i < 20; $i++) {
            User::factory()->create(['force_password_change' => false, 'nama_lengkap' => "Pengguna {$i}"]);
        }

        $response = $this->actingAs($admin)->get('/user');

        $response->assertOk();
        // 20 seeded + 1 admin = 21 rows; index() paginates 15/page, so the
        // full set must never render on a single page (proves paginate(),
        // not get()+loop, is actually being used).
        $totalSeen = substr_count($response->getContent(), 'Pengguna ');
        $this->assertLessThan(20, $totalSeen);

        $page2 = $this->actingAs($admin)->get('/user?page=2');
        $page2->assertOk();
    }

    public function test_admin_can_search_users_by_npp_or_nama(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        User::factory()->create(['force_password_change' => false, 'npp' => '9998887', 'nama_lengkap' => 'Budi Santoso']);
        User::factory()->create(['force_password_change' => false, 'npp' => '1112223', 'nama_lengkap' => 'Siti Aminah']);

        $response = $this->actingAs($admin)->get('/user?q=Budi');

        $response->assertOk()->assertSee('Budi Santoso')->assertDontSee('Siti Aminah');
    }

    public function test_admin_can_filter_users_by_role_and_kantor_and_status(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $kantorA = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $kantorB = Kantor::create(['kode' => 'B', 'nama' => 'Kantor B']);

        $salesA = User::factory()->create(['force_password_change' => false, 'role' => User::ROLE_SALES, 'nama_lengkap' => 'Sales Kantor A']);
        $salesA->kantor()->attach($kantorA->id);

        $salesB = User::factory()->create(['force_password_change' => false, 'role' => User::ROLE_SALES, 'nama_lengkap' => 'Sales Kantor B', 'is_active' => false]);
        $salesB->kantor()->attach($kantorB->id);

        $response = $this->actingAs($admin)->get('/user?role=sales&kantor='.$kantorA->id.'&is_active=1');

        $response->assertOk()->assertSee('Sales Kantor A')->assertDontSee('Sales Kantor B');
    }

    // ---------------- Create ----------------

    public function test_admin_can_create_a_user_with_password_set_to_npp_and_force_change_flag(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $kantor = Kantor::create(['kode' => 'X', 'nama' => 'Kantor X']);
        $unit = Unit::create(['nama' => 'BRANCH MANAGER', 'is_active' => true]);

        $response = $this->actingAs($admin)->post('/user', [
            'npp' => '5551234',
            'nama_lengkap' => 'User Baru',
            'unit_name' => $unit->nama,
            'role' => User::ROLE_SALES,
            'kantor_ids' => [$kantor->id],
        ]);

        $response->assertRedirect(route('user.index'));

        $user = User::where('npp', '5551234')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('5551234', $user->password));
        $this->assertTrue($user->force_password_change);
        $this->assertTrue($user->is_active);
        $this->assertSame(User::ROLE_SALES, $user->role);
        $this->assertSame($unit->id, $user->unit_id);
        $this->assertTrue($user->hasKantor($kantor->id));
    }

    public function test_admin_can_type_a_new_unit_name_and_it_is_auto_created_and_reused(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $kantor = Kantor::create(['kode' => 'X', 'nama' => 'Kantor X']);

        $response = $this->actingAs($admin)->post('/user', [
            'npp' => '5551235',
            'nama_lengkap' => 'User Baru Dua',
            'unit_name' => 'Kepala Cabang Baru',
            'role' => User::ROLE_SALES,
            'kantor_ids' => [$kantor->id],
        ]);

        $response->assertRedirect(route('user.index'));

        $unit = Unit::where('nama', 'Kepala Cabang Baru')->first();
        $this->assertNotNull($unit, 'typing a new unit name should auto-create it');
        $this->assertTrue($unit->is_active);

        $user = User::where('npp', '5551235')->first();
        $this->assertSame($unit->id, $user->unit_id);

        // Typing the same name again (different case/spacing) reuses the unit, doesn't duplicate it.
        $this->actingAs($admin)->post('/user', [
            'npp' => '5551236',
            'nama_lengkap' => 'User Baru Tiga',
            'unit_name' => '  kepala   cabang baru ',
            'role' => User::ROLE_SALES,
            'kantor_ids' => [$kantor->id],
        ]);

        $this->assertSame(1, Unit::where('nama', 'Kepala Cabang Baru')->count());
        $userThree = User::where('npp', '5551236')->first();
        $this->assertSame($unit->id, $userThree->unit_id);
    }

    public function test_create_rejects_duplicate_npp(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $kantor = Kantor::create(['kode' => 'X', 'nama' => 'Kantor X']);
        User::factory()->create(['npp' => '5551234', 'force_password_change' => false]);

        $response = $this->actingAs($admin)->post('/user', [
            'npp' => '5551234',
            'nama_lengkap' => 'User Baru',
            'role' => User::ROLE_SALES,
            'kantor_ids' => [$kantor->id],
        ]);

        $response->assertSessionHasErrors('npp');
        $this->assertSame(1, User::where('npp', '5551234')->count());
    }

    public function test_create_requires_at_least_one_kantor_for_non_admin_role(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);

        $response = $this->actingAs($admin)->post('/user', [
            'npp' => '5551234',
            'nama_lengkap' => 'User Baru',
            'role' => User::ROLE_SALES,
        ]);

        $response->assertSessionHasErrors('kantor_ids');
        $this->assertDatabaseMissing('users', ['npp' => '5551234']);
    }

    public function test_create_admin_role_does_not_require_kantor(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);

        $response = $this->actingAs($admin)->post('/user', [
            'npp' => '5559999',
            'nama_lengkap' => 'Admin Baru',
            'role' => User::ROLE_ADMIN,
        ]);

        $response->assertRedirect(route('user.index'));
        $newAdmin = User::where('npp', '5559999')->first();
        $this->assertNotNull($newAdmin);
        $this->assertSame(0, $newAdmin->kantor()->count());
    }

    // ---------------- Edit / update ----------------

    public function test_admin_can_update_a_users_profile_role_and_kantor_but_not_npp(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $kantorOld = Kantor::create(['kode' => 'OLD', 'nama' => 'Kantor Lama']);
        $kantorNew = Kantor::create(['kode' => 'NEW', 'nama' => 'Kantor Baru']);
        $unit = Unit::create(['nama' => 'STAFF', 'is_active' => true]);
        $user = User::factory()->create(['force_password_change' => false, 'npp' => '7778889', 'role' => User::ROLE_SALES]);
        $user->kantor()->attach($kantorOld->id);

        $response = $this->actingAs($admin)->put("/user/{$user->id}", [
            'npp' => '0000000', // must be ignored — npp is not editable
            'nama_lengkap' => 'Nama Diubah',
            'unit_name' => $unit->nama,
            'role' => User::ROLE_ADMIN_FINAL,
            'kantor_ids' => [$kantorNew->id],
        ]);

        $response->assertRedirect(route('user.edit', $user));
        $user->refresh();

        $this->assertSame('7778889', $user->npp, 'npp must not be changed via update()');
        $this->assertSame('Nama Diubah', $user->nama_lengkap);
        $this->assertSame($unit->id, $user->unit_id);
        $this->assertSame(User::ROLE_ADMIN_FINAL, $user->role);
        $this->assertTrue($user->hasKantor($kantorNew->id));
        $this->assertFalse($user->hasKantor($kantorOld->id));
    }

    // ---------------- Self-lockout guard ----------------

    public function test_admin_cannot_change_their_own_role_away_from_admin(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $kantor = Kantor::create(['kode' => 'X', 'nama' => 'Kantor X']);

        // kantor_ids is populated so the (unrelated) "sales needs a kantor"
        // validation rule doesn't mask the self-lockout error this test is
        // actually about.
        $response = $this->actingAs($admin)->put("/user/{$admin->id}", [
            'nama_lengkap' => $admin->nama_lengkap,
            'role' => User::ROLE_SALES,
            'kantor_ids' => [$kantor->id],
        ]);

        $response->assertSessionHasErrors('role');
        $this->assertSame(User::ROLE_ADMIN, $admin->fresh()->role);
    }

    public function test_admin_cannot_deactivate_their_own_account(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);

        $response = $this->actingAs($admin)->post("/user/{$admin->id}/toggle-active");

        $response->assertSessionHasErrors('is_active');
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_admin_can_deactivate_and_reactivate_another_user(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $target = User::factory()->create(['force_password_change' => false, 'is_active' => true]);

        $this->actingAs($admin)->post("/user/{$target->id}/toggle-active")->assertRedirect();
        $this->assertFalse($target->fresh()->is_active);

        $this->actingAs($admin)->post("/user/{$target->id}/toggle-active")->assertRedirect();
        $this->assertTrue($target->fresh()->is_active);
    }

    // ---------------- Deactivated user cannot log in (real login flow) ----------------

    public function test_deactivated_user_cannot_log_in(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $target = User::factory()->create([
            'npp' => '4443332',
            'password' => 'rahasia123',
            'force_password_change' => false,
            'is_active' => true,
        ]);

        $this->actingAs($admin)->post("/user/{$target->id}/toggle-active")->assertRedirect();
        $this->assertFalse($target->fresh()->is_active);

        // actingAs() binds $admin to the auth guard for every subsequent
        // request in this test (it's not session-cookie based), so the
        // 'guest' middleware on /login would otherwise short-circuit before
        // LoginController even runs — explicitly log out first to exercise
        // the real guest login flow.
        $this->post('/logout');

        $response = $this->post('/login', ['npp' => '4443332', 'password' => 'rahasia123']);

        $response->assertSessionHasErrors('npp');
        $this->assertGuest();
    }

    // ---------------- Reset password ----------------

    public function test_admin_can_reset_a_users_password_to_their_npp_and_reflag_force_change(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $target = User::factory()->create([
            'npp' => '3332221',
            'password' => 'somethingElse',
            'force_password_change' => false,
        ]);

        $response = $this->actingAs($admin)->post("/user/{$target->id}/reset-password");

        $response->assertRedirect();
        $target->refresh();
        $this->assertTrue(Hash::check('3332221', $target->password));
        $this->assertTrue($target->force_password_change);
    }

    public function test_reset_password_actually_lets_user_log_in_with_npp_afterwards(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $target = User::factory()->create([
            'npp' => '3332221',
            'password' => 'somethingElse',
            'force_password_change' => false,
        ]);

        $this->actingAs($admin)->post("/user/{$target->id}/reset-password")->assertRedirect();

        // See the matching note in test_deactivated_user_cannot_log_in():
        // actingAs() keeps $admin bound to the guard for later requests in
        // this test unless explicitly logged out first.
        $this->post('/logout');

        $response = $this->post('/login', ['npp' => '3332221', 'password' => '3332221']);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($target->fresh());
        $this->assertTrue($target->fresh()->force_password_change);
    }

    // ---------------- Hard delete ----------------

    public function test_admin_can_hard_delete_another_user(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $target = User::factory()->create(['force_password_change' => false]);

        $response = $this->actingAs($admin)->delete("/user/{$target->id}");

        $response->assertRedirect(route('user.index'));
        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    public function test_admin_cannot_hard_delete_their_own_account(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);

        $response = $this->actingAs($admin)->delete("/user/{$admin->id}");

        $response->assertSessionHasErrors('delete');
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    /**
     * The last-admin guard blocking a DIFFERENT acting admin (rather than a
     * self-delete) is not reachable through the real HTTP route: this whole
     * route group is gated `role:admin`, so whenever the target is the only
     * admin left, the acting user (who must themselves be an admin to even
     * reach this route) can only be that same admin — i.e. self-delete,
     * already covered by test_admin_cannot_hard_delete_their_own_account().
     * This test instead pins the guard's actual reachable boundary: deleting
     * an admin is fine as long as another admin still exists afterward.
     */
    public function test_admin_can_hard_delete_another_admin_when_more_than_one_admin_exists(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $otherAdmin = User::factory()->admin()->create(['force_password_change' => false]);

        $response = $this->actingAs($admin)->delete("/user/{$otherAdmin->id}");

        $response->assertRedirect(route('user.index'));
        $this->assertDatabaseMissing('users', ['id' => $otherAdmin->id]);
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    /**
     * poi_reopen_log.user_id has no cascade/null-on-delete (RESTRICT by
     * design, see UserController::hardDeleteBlockedReason()) — a user who
     * ever hapus/reopen'd a POI must be rejected with a clear reason instead
     * of a raw DB FK error.
     */
    public function test_admin_cannot_hard_delete_a_user_with_poi_reopen_log_history(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $target = User::factory()->create(['force_password_change' => false]);
        $poi = PoiFactory::new()->create();

        PoiReopenLog::create([
            'poi_id' => $poi->id,
            'action' => 'hapus',
            'alasan' => 'Duplikat data',
            'user_id' => $target->id,
        ]);

        $response = $this->actingAs($admin)->delete("/user/{$target->id}");

        $response->assertSessionHasErrors('delete');
        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }

    /**
     * kunjungan.sales_id IS cascadeOnDelete — a permitted hard delete
     * silently wipes the user's visit history along with the account. This
     * pins down that this is the actual (intended) behavior, not an
     * oversight, since a hard delete is meant to be a genuine full erasure.
     */
    public function test_hard_deleting_a_user_cascades_their_kunjungan_history(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $target = User::factory()->create(['force_password_change' => false]);
        $poi = PoiFactory::new()->create();

        $kunjungan = Kunjungan::create([
            'poi_id' => $poi->id,
            'sales_id' => $target->id,
            'tanggal_kunjungan' => now()->toDateString(),
            'hasil' => Kunjungan::HASIL_BELUM_BERTEMU,
        ]);

        $this->actingAs($admin)->delete("/user/{$target->id}")->assertRedirect();

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
        $this->assertDatabaseMissing('kunjungan', ['id' => $kunjungan->id]);
    }
}
