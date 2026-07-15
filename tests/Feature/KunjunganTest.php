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

/**
 * routes/kunjungan.php is intentionally NOT required by routes/web.php yet (per the
 * module boundary — wiring happens centrally once this and the parallel POI module are
 * both done). To exercise the real routes/controllers/middleware stack here, each test
 * registers the file itself with the same middleware the integrator will eventually use
 * (auth + force.password.change + active.kantor, mirroring the dashboard route in
 * web.php), scoped to this test's freshly-booted Application so nothing leaks into the
 * app's real routing.
 */
class KunjunganTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth', 'force.password.change', 'active.kantor'])
            ->group(base_path('routes/kunjungan.php'));

        // RouteServiceProvider::register() refreshes the route-name lookup table inside
        // an $app->booted() callback that has already fired by the time parent::setUp()
        // returns (it covers only routes/web.php) — routes registered afterwards (here)
        // dispatch fine by URI but are invisible to route()/redirect()->route() until the
        // name lookup is rebuilt again.
        Route::getRoutes()->refreshNameLookups();

        // DashboardSummaryService::allKantorId() requires this sentinel row to exist
        // (normally seeded by DatabaseSeeder, which RefreshDatabase does not run) —
        // without it, creating a Kunjungan throws before the KunjunganObserver can finish.
        Kantor::firstOrCreate(
            ['kode' => Kantor::SENTINEL_ALL_KODE],
            ['nama' => 'Seluruh Kantor', 'is_active' => false],
        );
    }

    private function poi(array $attributes = []): Poi
    {
        // Defaults to "not yet a BNI partner" — the only status a kunjungan can
        // target (see KunjunganController::prospectablePoiQuery) — since PoiFactory
        // otherwise randomizes status_mitra across all 3 values, which made this
        // suite flaky (~2/3 of runs would generate an already-BNI POI and every
        // store() test would fail validation). Override explicitly for tests that
        // specifically need an already-partnered POI.
        return PoiFactory::new()->create(array_merge(
            ['status_mitra' => Poi::BELUM_BERMITRA_BNI],
            $attributes,
        ));
    }

    private function sales(Kantor $kantor): User
    {
        $user = User::factory()->create(['force_password_change' => false, 'role' => User::ROLE_SALES]);
        $user->kantor()->attach($kantor->id);

        return $user;
    }

    // --- store() -------------------------------------------------------

    public function test_sales_can_log_a_kunjungan_for_a_poi_in_their_active_kantor(): void
    {
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $sales = $this->sales($kantor);
        $poi = $this->poi(['kantor_id' => $kantor->id, 'status' => 'aktif']);

        $response = $this->actingAs($sales)->post('/kunjungan', [
            'poi_id' => $poi->id,
            'produk' => [Kunjungan::PRODUK_OPTIONS[0], Kunjungan::PRODUK_OPTIONS[1]],
            'hasil' => Kunjungan::HASIL_BERMINAT,
            'catatan' => 'Kunjungan pertama.',
        ]);

        $response->assertRedirect('/kunjungan/riwayat');
        $this->assertDatabaseHas('kunjungan', [
            'poi_id' => $poi->id,
            'sales_id' => $sales->id,
            'hasil' => Kunjungan::HASIL_BERMINAT,
            'tanggal_kunjungan' => now()->toDateString(),
        ]);
        $this->assertDatabaseHas('kunjungan_produk', ['produk' => Kunjungan::PRODUK_OPTIONS[0]]);
        $this->assertDatabaseHas('kunjungan_produk', ['produk' => Kunjungan::PRODUK_OPTIONS[1]]);
    }

    public function test_sales_cannot_log_a_kunjungan_for_a_poi_already_partnered_with_bni(): void
    {
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $sales = $this->sales($kantor);
        $poi = $this->poi(['kantor_id' => $kantor->id, 'status' => 'aktif', 'status_mitra' => 'Nasabah Merchant BNI']);

        $response = $this->actingAs($sales)->post('/kunjungan', [
            'poi_id' => $poi->id,
            'hasil' => Kunjungan::HASIL_BERMINAT,
        ]);

        $response->assertSessionHasErrors('poi_id');
        $this->assertDatabaseMissing('kunjungan', ['poi_id' => $poi->id]);
    }

    public function test_hasil_closing_requires_status_mitra_baru_and_applies_it_to_the_poi(): void
    {
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $sales = $this->sales($kantor);
        $poi = $this->poi(['kantor_id' => $kantor->id, 'status' => 'aktif']);

        // Closing without picking the resulting status_mitra must be rejected.
        $this->actingAs($sales)->post('/kunjungan', [
            'poi_id' => $poi->id,
            'hasil' => Kunjungan::HASIL_CLOSING,
        ])->assertSessionHasErrors('status_mitra_baru');
        $this->assertDatabaseMissing('kunjungan', ['poi_id' => $poi->id]);

        $response = $this->actingAs($sales)->post('/kunjungan', [
            'poi_id' => $poi->id,
            'hasil' => Kunjungan::HASIL_CLOSING,
            'status_mitra_baru' => 'Nasabah Merchant BNI',
        ]);

        $response->assertRedirect('/kunjungan/riwayat');
        $this->assertDatabaseHas('kunjungan', ['poi_id' => $poi->id, 'hasil' => Kunjungan::HASIL_CLOSING]);
        $this->assertSame('Nasabah Merchant BNI', $poi->fresh()->status_mitra);
    }

    public function test_closing_stamps_the_poi_pic_with_the_closing_sales_name_and_unit(): void
    {
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $unit = Unit::create(['nama' => 'BTRM', 'is_active' => true]);
        $sales = $this->sales($kantor);
        $sales->update(['nama_lengkap' => 'Budi Santoso', 'unit_id' => $unit->id]);
        // Stale PIC from an old import / a previous closer at another unit — a new
        // closing must overwrite it, not preserve it (see picLabel() docblock).
        $poi = $this->poi(['kantor_id' => $kantor->id, 'status' => 'aktif', 'pic' => 'Samsul']);

        $this->actingAs($sales)->post('/kunjungan', [
            'poi_id' => $poi->id,
            'hasil' => Kunjungan::HASIL_CLOSING,
            'status_mitra_baru' => 'Nasabah Merchant BNI',
        ])->assertRedirect('/kunjungan/riwayat');

        $this->assertSame('Budi Santoso (BTRM)', $poi->fresh()->pic);
    }

    public function test_closing_stamps_the_poi_pic_with_just_the_name_when_sales_has_no_unit(): void
    {
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $sales = $this->sales($kantor);
        $sales->update(['nama_lengkap' => 'Budi Santoso']);
        $poi = $this->poi(['kantor_id' => $kantor->id, 'status' => 'aktif', 'pic' => null]);

        $this->actingAs($sales)->post('/kunjungan', [
            'poi_id' => $poi->id,
            'hasil' => Kunjungan::HASIL_CLOSING,
            'status_mitra_baru' => 'Nasabah Merchant BNI',
        ])->assertRedirect('/kunjungan/riwayat');

        $this->assertSame('Budi Santoso', $poi->fresh()->pic);
    }

    public function test_a_non_closing_kunjungan_leaves_the_poi_pic_untouched(): void
    {
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $sales = $this->sales($kantor);
        $poi = $this->poi(['kantor_id' => $kantor->id, 'status' => 'aktif', 'pic' => 'Samsul']);

        $this->actingAs($sales)->post('/kunjungan', [
            'poi_id' => $poi->id,
            'hasil' => Kunjungan::HASIL_BERMINAT,
        ])->assertRedirect('/kunjungan/riwayat');

        $this->assertSame('Samsul', $poi->fresh()->pic);
    }

    public function test_hasil_collecting_dokumen_locks_the_poi_to_the_acting_sales(): void
    {
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $sales = $this->sales($kantor);
        $otherSales = $this->sales($kantor);
        $poi = $this->poi(['kantor_id' => $kantor->id, 'status' => 'aktif']);

        $this->actingAs($sales)->post('/kunjungan', [
            'poi_id' => $poi->id,
            'hasil' => Kunjungan::HASIL_COLLECTING_DOKUMEN,
        ]);

        $this->assertSame($sales->id, $poi->fresh()->collecting_by);

        // A different sales is rejected outright while the lock holds.
        $response = $this->actingAs($otherSales)->post('/kunjungan', [
            'poi_id' => $poi->id,
            'hasil' => Kunjungan::HASIL_BERMINAT,
        ]);
        $response->assertSessionHasErrors('poi_id');

        // Any other hasil from the SAME sales clears the lock again.
        $this->actingAs($sales)->post('/kunjungan', [
            'poi_id' => $poi->id,
            'hasil' => Kunjungan::HASIL_BERMINAT,
        ]);
        $this->assertNull($poi->fresh()->collecting_by);
    }

    /**
     * Product decision (2026-07-15): a POI locked by ANOTHER sales stays in
     * the picker (not silently excluded like before) — the create() page's
     * embedded JSON payload flags it with who's collecting it, so the
     * frontend can explain the lock instead of the POI just disappearing.
     */
    public function test_create_shows_a_poi_locked_by_another_sales_with_who_is_collecting_it(): void
    {
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $collector = $this->sales($kantor);
        $collector->update(['npp' => '9998887', 'nama_lengkap' => 'Si Kolektor']);
        $viewer = $this->sales($kantor);
        $poi = $this->poi(['kantor_id' => $kantor->id, 'status' => 'aktif', 'nama_poi' => 'Toko Terkunci', 'collecting_by' => $collector->id]);

        $response = $this->actingAs($viewer)->get('/kunjungan/create');

        $response->assertOk();
        $poiOptions = $response->viewData('poiOptions');
        $this->assertTrue($poiOptions->contains('id', $poi->id), 'Locked POI must still be listed, not excluded.');

        $html = $response->getContent();
        $this->assertStringContainsString('"lockedBy":{"npp":"9998887","nama":"Si Kolektor"}', $html);
    }

    /**
     * The SAME sales who's collecting a POI sees it as normal/unlocked in
     * their own picker — only a DIFFERENT sales' lock triggers the notice.
     */
    public function test_create_does_not_flag_a_poi_as_locked_for_the_sales_who_is_collecting_it(): void
    {
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $sales = $this->sales($kantor);
        $poi = $this->poi(['kantor_id' => $kantor->id, 'status' => 'aktif', 'nama_poi' => 'Toko Milik Sendiri', 'collecting_by' => $sales->id]);

        $response = $this->actingAs($sales)->get('/kunjungan/create');

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('Toko Milik Sendiri', $html);
        $this->assertStringNotContainsString('"lockedBy":{"npp"', $html);
    }

    public function test_admin_final_can_log_a_kunjungan_for_one_of_their_own_kantor(): void
    {
        $kantorMine = Kantor::create(['kode' => 'MINE', 'nama' => 'Kantor Saya']);
        $kantorOther = Kantor::create(['kode' => 'OTHER', 'nama' => 'Kantor Lain']);
        $adminFinal = User::factory()->adminFinal()->create(['force_password_change' => false]);
        $adminFinal->kantor()->attach($kantorMine->id);
        $poiMine = $this->poi(['kantor_id' => $kantorMine->id, 'status' => 'aktif']);
        $poiOther = $this->poi(['kantor_id' => $kantorOther->id, 'status' => 'aktif']);

        // Forging kantor_id to a kantor they don't own must be rejected (403), not
        // silently fall back — this is a write action, not a read-scope narrowing.
        $this->actingAs($adminFinal)->post('/kunjungan', [
            'kantor_id' => $kantorOther->id,
            'poi_id' => $poiOther->id,
            'hasil' => Kunjungan::HASIL_BERMINAT,
        ])->assertForbidden();

        $response = $this->actingAs($adminFinal)->post('/kunjungan', [
            'kantor_id' => $kantorMine->id,
            'poi_id' => $poiMine->id,
            'hasil' => Kunjungan::HASIL_BERMINAT,
        ]);

        $response->assertRedirect('/kunjungan?kantor_id='.$kantorMine->id);
        $this->assertDatabaseHas('kunjungan', ['poi_id' => $poiMine->id, 'sales_id' => $adminFinal->id]);
    }

    public function test_sales_cannot_log_a_kunjungan_for_a_forged_poi_id_outside_their_active_kantor(): void
    {
        $kantorMine = Kantor::create(['kode' => 'MINE', 'nama' => 'Kantor Saya']);
        $kantorOther = Kantor::create(['kode' => 'OTHER', 'nama' => 'Kantor Lain']);
        $sales = $this->sales($kantorMine);
        $poiOther = $this->poi(['kantor_id' => $kantorOther->id, 'status' => 'aktif']);

        $response = $this->actingAs($sales)->post('/kunjungan', [
            'poi_id' => $poiOther->id,
            'tanggal_kunjungan' => now()->toDateString(),
            'hasil' => Kunjungan::HASIL_BERMINAT,
        ]);

        $response->assertSessionHasErrors('poi_id');
        $this->assertDatabaseMissing('kunjungan', ['poi_id' => $poiOther->id]);
    }

    public function test_sales_cannot_log_a_kunjungan_for_a_nonaktif_poi(): void
    {
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $sales = $this->sales($kantor);
        $poi = $this->poi(['kantor_id' => $kantor->id, 'status' => 'nonaktif']);

        $response = $this->actingAs($sales)->post('/kunjungan', [
            'poi_id' => $poi->id,
            'tanggal_kunjungan' => now()->toDateString(),
            'hasil' => Kunjungan::HASIL_BERMINAT,
        ]);

        $response->assertSessionHasErrors('poi_id');
        $this->assertDatabaseMissing('kunjungan', ['poi_id' => $poi->id]);
    }

    public function test_sales_id_is_always_the_authenticated_user_even_if_spoofed(): void
    {
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $sales = $this->sales($kantor);
        $otherSales = User::factory()->create(['role' => User::ROLE_SALES]);
        $poi = $this->poi(['kantor_id' => $kantor->id, 'status' => 'aktif']);

        $this->actingAs($sales)->post('/kunjungan', [
            'poi_id' => $poi->id,
            'sales_id' => $otherSales->id,
            'tanggal_kunjungan' => now()->toDateString(),
            'hasil' => Kunjungan::HASIL_BERMINAT,
        ]);

        $this->assertDatabaseHas('kunjungan', ['poi_id' => $poi->id, 'sales_id' => $sales->id]);
        $this->assertDatabaseMissing('kunjungan', ['poi_id' => $poi->id, 'sales_id' => $otherSales->id]);
    }

    public function test_invalid_hasil_value_is_rejected(): void
    {
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $sales = $this->sales($kantor);
        $poi = $this->poi(['kantor_id' => $kantor->id, 'status' => 'aktif']);

        $response = $this->actingAs($sales)->post('/kunjungan', [
            'poi_id' => $poi->id,
            'tanggal_kunjungan' => now()->toDateString(),
            'hasil' => 'Tidak Valid',
        ]);

        $response->assertSessionHasErrors('hasil');
    }

    public function test_creating_a_kunjungan_updates_the_dashboard_summary_row(): void
    {
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $sales = $this->sales($kantor);
        $poi = $this->poi(['kantor_id' => $kantor->id, 'status' => 'aktif']);

        $before = \DB::table('dashboard_summary')->where('kantor_id', $kantor->id)
            ->where('tanggal', now()->toDateString())->first();

        $this->actingAs($sales)->post('/kunjungan', [
            'poi_id' => $poi->id,
            'hasil' => Kunjungan::HASIL_CLOSING,
            'status_mitra_baru' => 'Nasabah Merchant BNI',
        ]);

        $after = \DB::table('dashboard_summary')->where('kantor_id', $kantor->id)
            ->where('tanggal', now()->toDateString())->first();

        $this->assertNotNull($after, 'Expected the KunjunganObserver to create/update a dashboard_summary row.');
        $beforeClosing = $before?->total_closing ?? 0;
        $beforeKunjungan = $before?->total_kunjungan ?? 0;
        $this->assertSame($beforeClosing + 1, $after->total_closing);
        $this->assertSame($beforeKunjungan + 1, $after->total_kunjungan);
    }

    // --- riwayat() (sales, personal) ------------------------------------

    public function test_sales_only_sees_their_own_riwayat(): void
    {
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $sales = $this->sales($kantor);
        $otherSales = $this->sales($kantor);
        $poi = $this->poi(['kantor_id' => $kantor->id, 'status' => 'aktif']);

        Kunjungan::create([
            'poi_id' => $poi->id, 'sales_id' => $sales->id,
            'tanggal_kunjungan' => now(), 'hasil' => Kunjungan::HASIL_BERMINAT,
        ]);
        Kunjungan::create([
            'poi_id' => $poi->id, 'sales_id' => $otherSales->id,
            'tanggal_kunjungan' => now(), 'hasil' => Kunjungan::HASIL_CLOSING,
        ]);

        $response = $this->actingAs($sales)->get('/kunjungan/riwayat');

        $response->assertOk();
        $ids = $response->viewData('kunjungans')->pluck('sales_id')->unique();
        $this->assertEquals([$sales->id], $ids->all());
    }

    public function test_riwayat_pagination_is_actually_used(): void
    {
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $sales = $this->sales($kantor);
        $poi = $this->poi(['kantor_id' => $kantor->id, 'status' => 'aktif']);

        for ($i = 0; $i < 20; $i++) {
            Kunjungan::create([
                'poi_id' => $poi->id, 'sales_id' => $sales->id,
                'tanggal_kunjungan' => now()->subDays($i), 'hasil' => Kunjungan::HASIL_BERMINAT,
            ]);
        }

        $response = $this->actingAs($sales)->get('/kunjungan/riwayat');

        $response->assertOk();
        $paginator = $response->viewData('kunjungans');
        $this->assertLessThan(20, $paginator->count());
        $this->assertTrue($paginator->hasMorePages());
    }

    public function test_admin_and_sales_role_gates_are_enforced(): void
    {
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $sales = $this->sales($kantor);
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $adminFinal = User::factory()->adminFinal()->create(['force_password_change' => false]);
        $adminFinal->kantor()->attach($kantor->id);

        // riwayat (kantor-wide) is admin/admin_final only, sales gets their own instead.
        $this->actingAs($sales)->get('/kunjungan')->assertForbidden();
        // Logging visits: sales, admin_final, and admin (opened up for testing/checking
        // purposes) can all reach the form.
        $this->actingAs($admin)->get('/kunjungan/create')->assertOk();
        $this->actingAs($sales)->get('/kunjungan/create')->assertOk();
        $this->actingAs($adminFinal)->get('/kunjungan/create')->assertOk();
    }

    public function test_admin_can_log_a_kunjungan_for_any_kantor(): void
    {
        $kantorA = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $poi = $this->poi(['kantor_id' => $kantorA->id, 'status' => 'aktif']);

        $response = $this->actingAs($admin)->post('/kunjungan', [
            'kantor_id' => $kantorA->id,
            'poi_id' => $poi->id,
            'hasil' => Kunjungan::HASIL_BERMINAT,
        ]);

        $response->assertRedirect('/kunjungan?kantor_id='.$kantorA->id);
        $this->assertDatabaseHas('kunjungan', ['poi_id' => $poi->id, 'sales_id' => $admin->id]);
    }

    // --- index() (admin / admin_final, kantor riwayat) -------------------

    public function test_admin_final_kantor_riwayat_is_scoped_to_their_own_kantor_only(): void
    {
        $kantorMine = Kantor::create(['kode' => 'MINE', 'nama' => 'Kantor Saya']);
        $kantorOther = Kantor::create(['kode' => 'OTHER', 'nama' => 'Kantor Lain']);

        $adminFinal = User::factory()->adminFinal()->create(['force_password_change' => false]);
        $adminFinal->kantor()->attach($kantorMine->id);

        $salesMine = $this->sales($kantorMine);
        $salesOther = $this->sales($kantorOther);
        $poiMine = $this->poi(['kantor_id' => $kantorMine->id, 'status' => 'aktif']);
        $poiOther = $this->poi(['kantor_id' => $kantorOther->id, 'status' => 'aktif']);

        $kMine = Kunjungan::create([
            'poi_id' => $poiMine->id, 'sales_id' => $salesMine->id,
            'tanggal_kunjungan' => now(), 'hasil' => Kunjungan::HASIL_BERMINAT,
        ]);
        Kunjungan::create([
            'poi_id' => $poiOther->id, 'sales_id' => $salesOther->id,
            'tanggal_kunjungan' => now(), 'hasil' => Kunjungan::HASIL_CLOSING,
        ]);

        $response = $this->actingAs($adminFinal)->get('/kunjungan');

        $response->assertOk();
        $ids = $response->viewData('kunjungans')->pluck('id');
        $this->assertTrue($ids->contains($kMine->id));
        $this->assertEquals(1, $ids->count());
    }

    public function test_admin_final_cannot_see_other_kantor_via_forged_kantor_filter(): void
    {
        $kantorMine = Kantor::create(['kode' => 'MINE', 'nama' => 'Kantor Saya']);
        $kantorOther = Kantor::create(['kode' => 'OTHER', 'nama' => 'Kantor Lain']);

        $adminFinal = User::factory()->adminFinal()->create(['force_password_change' => false]);
        $adminFinal->kantor()->attach($kantorMine->id);

        $salesOther = $this->sales($kantorOther);
        $poiOther = $this->poi(['kantor_id' => $kantorOther->id, 'status' => 'aktif']);
        Kunjungan::create([
            'poi_id' => $poiOther->id, 'sales_id' => $salesOther->id,
            'tanggal_kunjungan' => now(), 'hasil' => Kunjungan::HASIL_CLOSING,
        ]);

        $response = $this->actingAs($adminFinal)->get('/kunjungan?kantor_id='.$kantorOther->id);

        $response->assertOk();
        $this->assertEquals(0, $response->viewData('kunjungans')->count());
    }

    public function test_admin_sees_kunjungan_across_all_kantor(): void
    {
        $kantorA = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $kantorB = Kantor::create(['kode' => 'B', 'nama' => 'Kantor B']);
        $admin = User::factory()->admin()->create(['force_password_change' => false]);

        $salesA = $this->sales($kantorA);
        $salesB = $this->sales($kantorB);
        $poiA = $this->poi(['kantor_id' => $kantorA->id, 'status' => 'aktif']);
        $poiB = $this->poi(['kantor_id' => $kantorB->id, 'status' => 'aktif']);

        Kunjungan::create([
            'poi_id' => $poiA->id, 'sales_id' => $salesA->id,
            'tanggal_kunjungan' => now(), 'hasil' => Kunjungan::HASIL_BERMINAT,
        ]);
        Kunjungan::create([
            'poi_id' => $poiB->id, 'sales_id' => $salesB->id,
            'tanggal_kunjungan' => now(), 'hasil' => Kunjungan::HASIL_CLOSING,
        ]);

        $response = $this->actingAs($admin)->get('/kunjungan');

        $response->assertOk();
        $this->assertEquals(2, $response->viewData('kunjungans')->count());
    }

    public function test_index_pagination_is_actually_used(): void
    {
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $sales = $this->sales($kantor);
        $poi = $this->poi(['kantor_id' => $kantor->id, 'status' => 'aktif']);

        for ($i = 0; $i < 20; $i++) {
            Kunjungan::create([
                'poi_id' => $poi->id, 'sales_id' => $sales->id,
                'tanggal_kunjungan' => now()->subDays($i), 'hasil' => Kunjungan::HASIL_BERMINAT,
            ]);
        }

        $response = $this->actingAs($admin)->get('/kunjungan');

        $response->assertOk();
        $paginator = $response->viewData('kunjungans');
        $this->assertLessThan(20, $paginator->count());
        $this->assertTrue($paginator->hasMorePages());
    }

    /**
     * "Export Excel" is a button on this page (not a separate form) — it must
     * carry through whatever filters are currently active on the table,
     * otherwise "export what I'm looking at" silently exports everything.
     */
    public function test_riwayat_kunjungan_table_displays_the_poi_pic(): void
    {
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $poi = $this->poi(['kantor_id' => $kantor->id, 'pic' => 'Budi Santoso (BTRM)']);
        $sales = $this->sales($kantor);
        Kunjungan::create([
            'poi_id' => $poi->id, 'sales_id' => $sales->id,
            'tanggal_kunjungan' => now()->toDateString(), 'hasil' => Kunjungan::HASIL_CLOSING,
        ]);

        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $response = $this->actingAs($admin)->get('/kunjungan');

        $response->assertOk();
        $response->assertSee('Budi Santoso (BTRM)');
    }

    public function test_export_button_carries_through_the_active_filters(): void
    {
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $admin = User::factory()->admin()->create(['force_password_change' => false]);

        $response = $this->actingAs($admin)->get('/kunjungan?hasil=Closing&kantor_id='.$kantor->id.'&dari=2026-01-01');

        $response->assertOk();
        // Query-param order in the rendered href isn't guaranteed, so check the
        // export path plus each active filter is present rather than one exact URL.
        $html = $response->getContent();
        $this->assertStringContainsString('/export/kunjungan/download', $html);
        $this->assertStringContainsString('hasil=Closing', $html);
        $this->assertStringContainsString('kantor_id='.$kantor->id, $html);
        $this->assertStringContainsString('dari=2026-01-01', $html);
    }

    // --- reopen() --------------------------------------------------------

    public function test_admin_reopens_a_closing_and_the_poi_and_dashboard_summary_revert(): void
    {
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $sales = $this->sales($kantor);
        $poi = $this->poi(['kantor_id' => $kantor->id, 'status' => 'aktif']);

        $this->actingAs($sales)->post('/kunjungan', [
            'poi_id' => $poi->id,
            'hasil' => Kunjungan::HASIL_CLOSING,
            'status_mitra_baru' => 'Nasabah Merchant BNI',
        ])->assertRedirect('/kunjungan/riwayat');

        $kunjungan = Kunjungan::where('poi_id', $poi->id)->firstOrFail();
        $this->assertSame('Nasabah Merchant BNI', $poi->fresh()->status_mitra);
        $this->assertDatabaseHas('dashboard_summary', ['kantor_id' => $kantor->id, 'total_closing' => 1, 'total_kunjungan' => 1]);

        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $response = $this->actingAs($admin)->post("/kunjungan/{$kunjungan->id}/reopen");

        $response->assertRedirect();
        $response->assertSessionHas('status');
        $this->assertSame(Poi::BELUM_BERMITRA_BNI, $poi->fresh()->status_mitra);
        $this->assertDatabaseMissing('kunjungan', ['id' => $kunjungan->id]);
        $this->assertDatabaseHas('dashboard_summary', ['kantor_id' => $kantor->id, 'total_closing' => 0, 'total_kunjungan' => 0]);
    }

    public function test_admin_reopens_a_collecting_dokumen_and_the_lock_clears(): void
    {
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $sales = $this->sales($kantor);
        $poi = $this->poi(['kantor_id' => $kantor->id, 'status' => 'aktif']);

        $this->actingAs($sales)->post('/kunjungan', [
            'poi_id' => $poi->id,
            'hasil' => Kunjungan::HASIL_COLLECTING_DOKUMEN,
        ])->assertRedirect('/kunjungan/riwayat');

        $kunjungan = Kunjungan::where('poi_id', $poi->id)->firstOrFail();
        $this->assertSame($sales->id, $poi->fresh()->collecting_by);

        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $this->actingAs($admin)->post("/kunjungan/{$kunjungan->id}/reopen")->assertRedirect();

        $this->assertNull($poi->fresh()->collecting_by);
        $this->assertDatabaseMissing('kunjungan', ['id' => $kunjungan->id]);
    }

    public function test_reopen_is_rejected_for_a_hasil_that_is_not_closing_or_collecting_dokumen(): void
    {
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $sales = $this->sales($kantor);
        $poi = $this->poi(['kantor_id' => $kantor->id, 'status' => 'aktif']);

        $this->actingAs($sales)->post('/kunjungan', [
            'poi_id' => $poi->id,
            'hasil' => Kunjungan::HASIL_BERMINAT,
        ])->assertRedirect('/kunjungan/riwayat');

        $kunjungan = Kunjungan::where('poi_id', $poi->id)->firstOrFail();

        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $this->actingAs($admin)->post("/kunjungan/{$kunjungan->id}/reopen")->assertNotFound();

        $this->assertDatabaseHas('kunjungan', ['id' => $kunjungan->id]);
    }

    /**
     * A newer kunjungan already exists for the same POI (the sales cleared
     * the collecting lock by logging a second visit with a different hasil)
     * — reopening the OLDER Collecting Dokumen entry out of order would
     * corrupt that newer state, so it's rejected instead.
     */
    public function test_reopen_is_rejected_when_a_newer_kunjungan_exists_for_the_same_poi(): void
    {
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $sales = $this->sales($kantor);
        $poi = $this->poi(['kantor_id' => $kantor->id, 'status' => 'aktif']);

        $this->actingAs($sales)->post('/kunjungan', [
            'poi_id' => $poi->id,
            'hasil' => Kunjungan::HASIL_COLLECTING_DOKUMEN,
        ])->assertRedirect('/kunjungan/riwayat');
        $firstKunjungan = Kunjungan::where('poi_id', $poi->id)->firstOrFail();

        $this->actingAs($sales)->post('/kunjungan', [
            'poi_id' => $poi->id,
            'hasil' => Kunjungan::HASIL_BERMINAT,
        ])->assertRedirect('/kunjungan/riwayat');

        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $response = $this->actingAs($admin)->post("/kunjungan/{$firstKunjungan->id}/reopen");

        $response->assertRedirect();
        $response->assertSessionHasErrors();
        $this->assertDatabaseHas('kunjungan', ['id' => $firstKunjungan->id]);
    }

    public function test_admin_final_can_reopen_for_their_own_kantor_but_not_for_others(): void
    {
        $mine = Kantor::create(['kode' => 'MINE', 'nama' => 'Kantor Mine']);
        $other = Kantor::create(['kode' => 'OTHER', 'nama' => 'Kantor Other']);
        $salesMine = $this->sales($mine);
        $salesOther = $this->sales($other);
        $poiMine = $this->poi(['kantor_id' => $mine->id, 'status' => 'aktif']);
        $poiOther = $this->poi(['kantor_id' => $other->id, 'status' => 'aktif']);

        $this->actingAs($salesMine)->post('/kunjungan', [
            'poi_id' => $poiMine->id, 'hasil' => Kunjungan::HASIL_COLLECTING_DOKUMEN,
        ])->assertRedirect('/kunjungan/riwayat');
        $this->actingAs($salesOther)->post('/kunjungan', [
            'poi_id' => $poiOther->id, 'hasil' => Kunjungan::HASIL_COLLECTING_DOKUMEN,
        ])->assertRedirect('/kunjungan/riwayat');

        $kunjunganMine = Kunjungan::where('poi_id', $poiMine->id)->firstOrFail();
        $kunjunganOther = Kunjungan::where('poi_id', $poiOther->id)->firstOrFail();

        $adminFinal = User::factory()->adminFinal()->create(['force_password_change' => false]);
        $adminFinal->kantor()->attach($mine->id);

        $this->actingAs($adminFinal)->post("/kunjungan/{$kunjunganMine->id}/reopen")->assertRedirect();
        $this->assertDatabaseMissing('kunjungan', ['id' => $kunjunganMine->id]);

        $this->actingAs($adminFinal)->post("/kunjungan/{$kunjunganOther->id}/reopen")->assertForbidden();
        $this->assertDatabaseHas('kunjungan', ['id' => $kunjunganOther->id]);
    }

    public function test_sales_cannot_reopen_a_kunjungan(): void
    {
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $sales = $this->sales($kantor);
        $poi = $this->poi(['kantor_id' => $kantor->id, 'status' => 'aktif']);

        $this->actingAs($sales)->post('/kunjungan', [
            'poi_id' => $poi->id, 'hasil' => Kunjungan::HASIL_COLLECTING_DOKUMEN,
        ])->assertRedirect('/kunjungan/riwayat');
        $kunjungan = Kunjungan::where('poi_id', $poi->id)->firstOrFail();

        $this->actingAs($sales)->post("/kunjungan/{$kunjungan->id}/reopen")->assertForbidden();
        $this->assertDatabaseHas('kunjungan', ['id' => $kunjungan->id]);
    }

    public function test_reopen_button_only_shows_on_the_latest_closing_or_collecting_row(): void
    {
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $sales = $this->sales($kantor);

        // POI A: Collecting Dokumen superseded by a later Berminat visit —
        // the old Collecting Dokumen row is no longer reopenable.
        $poiSuperseded = $this->poi(['kantor_id' => $kantor->id, 'status' => 'aktif']);
        $this->actingAs($sales)->post('/kunjungan', [
            'poi_id' => $poiSuperseded->id, 'hasil' => Kunjungan::HASIL_COLLECTING_DOKUMEN,
        ])->assertRedirect('/kunjungan/riwayat');
        $supersededKunjungan = Kunjungan::where('poi_id', $poiSuperseded->id)->firstOrFail();
        $this->actingAs($sales)->post('/kunjungan', [
            'poi_id' => $poiSuperseded->id, 'hasil' => Kunjungan::HASIL_BERMINAT,
        ])->assertRedirect('/kunjungan/riwayat');

        // POI B: a fresh Closing, still the latest (and only) kunjungan —
        // reopenable.
        $poiFresh = $this->poi(['kantor_id' => $kantor->id, 'status' => 'aktif']);
        $this->actingAs($sales)->post('/kunjungan', [
            'poi_id' => $poiFresh->id, 'hasil' => Kunjungan::HASIL_CLOSING,
            'status_mitra_baru' => 'Nasabah Merchant BNI',
        ])->assertRedirect('/kunjungan/riwayat');
        $freshKunjungan = Kunjungan::where('poi_id', $poiFresh->id)->firstOrFail();

        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $response = $this->actingAs($admin)->get('/kunjungan');

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('/kunjungan/'.$freshKunjungan->id.'/reopen', $html);
        $this->assertStringNotContainsString('/kunjungan/'.$supersededKunjungan->id.'/reopen', $html);
    }
}
