<?php

namespace Tests\Feature;

use App\Models\DashboardSummary;
use App\Models\Kantor;
use App\Models\Poi;
use App\Models\PoiReopenLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RegistersPoiRoutes;
use Tests\TestCase;

class PoiTest extends TestCase
{
    use RefreshDatabase;
    use RegistersPoiRoutes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerPoiRoutes();

        // PoiObserver -> DashboardSummaryService requires the sentinel "ALL"
        // kantor row to exist (normally seeded by DatabaseSeeder).
        Kantor::firstOrCreate(
            ['kode' => Kantor::SENTINEL_ALL_KODE],
            ['nama' => 'Seluruh Kantor', 'is_active' => false],
        );
    }

    private function makePoi(Kantor $kantor, array $overrides = []): Poi
    {
        return Poi::create(array_merge([
            'nama_poi' => 'Toko Contoh',
            'alamat' => 'Jl. Contoh No. 1',
            'sektor' => Poi::SEKTOR_OPTIONS[0],
            'kantor_id' => $kantor->id,
            'status_mitra' => Poi::STATUS_MITRA_OPTIONS[0],
            'status' => 'aktif',
        ], $overrides));
    }

    // ---------------- Index / listing scope ----------------

    public function test_admin_sees_all_kantor_in_index(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $kantorA = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $kantorB = Kantor::create(['kode' => 'B', 'nama' => 'Kantor B']);
        $this->makePoi($kantorA, ['nama_poi' => 'POI A']);
        $this->makePoi($kantorB, ['nama_poi' => 'POI B']);

        $response = $this->actingAs($admin)->get('/poi');

        $response->assertOk()->assertSee('POI A')->assertSee('POI B');
    }

    public function test_index_colors_the_status_mitra_badge_green_for_bni_and_red_otherwise(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $belumBni = $this->makePoi($kantor, ['nama_poi' => 'Belum BNI', 'status_mitra' => Poi::BELUM_BERMITRA_BNI]);
        $merchant = $this->makePoi($kantor, ['nama_poi' => 'Sudah Merchant', 'status_mitra' => 'Nasabah Merchant BNI']);

        $this->assertSame('badge-no', $belumBni->statusMitraBadgeClass());
        $this->assertSame('badge-ok', $merchant->statusMitraBadgeClass());

        $response = $this->actingAs($admin)->get('/poi');
        $response->assertOk();
        $html = $response->getContent();
        $this->assertMatchesRegularExpression('/badge badge-no">Bukan Nasabah BNI/', $html);
        $this->assertMatchesRegularExpression('/badge badge-ok">Nasabah Merchant BNI/', $html);
    }

    public function test_admin_final_index_is_scoped_to_owned_kantor_only(): void
    {
        $adminFinal = User::factory()->adminFinal()->create(['force_password_change' => false]);
        $kantorMine = Kantor::create(['kode' => 'MINE', 'nama' => 'Kantor Mine']);
        $kantorOther = Kantor::create(['kode' => 'OTHER', 'nama' => 'Kantor Other']);
        $adminFinal->kantor()->attach($kantorMine->id);

        $this->makePoi($kantorMine, ['nama_poi' => 'POI Mine']);
        $this->makePoi($kantorOther, ['nama_poi' => 'POI Other']);

        $response = $this->actingAs($adminFinal)->get('/poi');

        $response->assertOk()->assertSee('POI Mine')->assertDontSee('POI Other');
    }

    public function test_admin_final_kantor_filter_is_ignored_if_not_owned(): void
    {
        $adminFinal = User::factory()->adminFinal()->create(['force_password_change' => false]);
        $kantorMine = Kantor::create(['kode' => 'MINE', 'nama' => 'Kantor Mine']);
        $kantorOther = Kantor::create(['kode' => 'OTHER', 'nama' => 'Kantor Other']);
        $adminFinal->kantor()->attach($kantorMine->id);

        $this->makePoi($kantorMine, ['nama_poi' => 'POI Mine']);
        $this->makePoi($kantorOther, ['nama_poi' => 'POI Other']);

        // Attempt IDOR via ?kantor= for a kantor this admin_final does not own.
        $response = $this->actingAs($adminFinal)->get('/poi?kantor='.$kantorOther->id);

        $response->assertOk()->assertSee('POI Mine')->assertDontSee('POI Other');
    }

    // ---------------- Area / Cabang-Cluster filter (2026-07-23) ----------------

    public function test_area_filter_narrows_the_poi_list_to_only_that_areas_kantor(): void
    {
        $jakarta = Kantor::create(['kode' => 'A', 'nama' => 'Cabang Jakarta', 'area' => 'Area Jakarta']);
        $bandung = Kantor::create(['kode' => 'B', 'nama' => 'Cabang Bandung', 'area' => 'Area Jabar']);
        $this->makePoi($jakarta, ['nama_poi' => 'POI Jakarta']);
        $this->makePoi($bandung, ['nama_poi' => 'POI Bandung']);

        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $response = $this->actingAs($admin)->get('/poi?area='.urlencode('Area Jakarta'));

        $response->assertOk()->assertSee('POI Jakarta')->assertDontSee('POI Bandung');
    }

    public function test_cluster_filter_narrows_within_the_selected_area(): void
    {
        $clusterA = Kantor::create(['kode' => 'A', 'nama' => 'Cabang A', 'area' => 'Area X', 'cabang_cluster' => 'Cluster A']);
        $clusterB = Kantor::create(['kode' => 'B', 'nama' => 'Cabang B', 'area' => 'Area X', 'cabang_cluster' => 'Cluster B']);
        $this->makePoi($clusterA, ['nama_poi' => 'POI Cluster A']);
        $this->makePoi($clusterB, ['nama_poi' => 'POI Cluster B']);

        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $response = $this->actingAs($admin)->get('/poi?area='.urlencode('Area X').'&cluster='.urlencode('Cluster A'));

        $response->assertOk()->assertSee('POI Cluster A')->assertDontSee('POI Cluster B');
    }

    public function test_ring_area_filter_still_works_independently_of_the_new_area_filter(): void
    {
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $this->makePoi($kantor, ['nama_poi' => 'POI Ring 1', 'area' => Poi::AREA_OPTIONS[0]]);
        $this->makePoi($kantor, ['nama_poi' => 'POI Ring 2', 'area' => Poi::AREA_OPTIONS[1]]);

        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $response = $this->actingAs($admin)->get('/poi?ring_area='.urlencode(Poi::AREA_OPTIONS[0]));

        $response->assertOk()->assertSee('POI Ring 1')->assertDontSee('POI Ring 2');
    }

    public function test_admin_finals_area_options_are_scoped_to_their_own_kantor_only(): void
    {
        $mine = Kantor::create(['kode' => 'MINE', 'nama' => 'Kantor Mine', 'area' => 'Area Saya']);
        $other = Kantor::create(['kode' => 'OTHER', 'nama' => 'Kantor Other', 'area' => 'Area Lain']);
        $adminFinal = User::factory()->adminFinal()->create(['force_password_change' => false]);
        $adminFinal->kantor()->attach($mine->id);

        $this->makePoi($mine, ['nama_poi' => 'POI Mine']);
        $this->makePoi($other, ['nama_poi' => 'POI Other']);

        // Forging an area they have no kantor in must not leak Other's POI in
        // (falls back to their own full scope instead).
        $response = $this->actingAs($adminFinal)->get('/poi?area='.urlencode('Area Lain'));

        $response->assertOk()->assertSee('POI Mine')->assertDontSee('POI Other');
    }

    public function test_sales_index_is_scoped_to_their_active_kantor_only(): void
    {
        $sales = User::factory()->create(['force_password_change' => false, 'role' => User::ROLE_SALES]);
        $kantorMine = Kantor::create(['kode' => 'MINE', 'nama' => 'Kantor Mine']);
        $kantorOther = Kantor::create(['kode' => 'OTHER', 'nama' => 'Kantor Other']);
        $sales->kantor()->attach($kantorMine->id);

        $this->makePoi($kantorMine, ['nama_poi' => 'POI Mine']);
        $this->makePoi($kantorOther, ['nama_poi' => 'POI Other']);

        $response = $this->actingAs($sales)->get('/poi');

        $response->assertOk()->assertSee('POI Mine')->assertDontSee('POI Other');
    }

    public function test_pagination_does_not_leak_other_kantor_rows_to_admin_final(): void
    {
        $adminFinal = User::factory()->adminFinal()->create(['force_password_change' => false]);
        $kantorMine = Kantor::create(['kode' => 'MINE', 'nama' => 'Kantor Mine']);
        $kantorOther = Kantor::create(['kode' => 'OTHER', 'nama' => 'Kantor Other']);
        $adminFinal->kantor()->attach($kantorMine->id);

        for ($i = 0; $i < 20; $i++) {
            $this->makePoi($kantorMine, ['nama_poi' => "Mine POI {$i}"]);
        }
        for ($i = 0; $i < 20; $i++) {
            $this->makePoi($kantorOther, ['nama_poi' => "Other POI {$i}"]);
        }

        // Page 1 and page 2 combined must never exceed the 20 rows this
        // admin_final actually owns, regardless of how many "Other" rows exist.
        $totalSeen = 0;
        foreach ([1, 2] as $page) {
            $response = $this->actingAs($adminFinal)->get('/poi?page='.$page);
            $response->assertOk();
            $totalSeen += substr_count($response->getContent(), 'Mine POI');
            $this->assertStringNotContainsString('Other POI', $response->getContent());
        }

        $this->assertSame(20, $totalSeen);
    }

    // ---------------- Read access (show) ----------------

    public function test_admin_final_cannot_view_poi_of_unowned_kantor(): void
    {
        $adminFinal = User::factory()->adminFinal()->create(['force_password_change' => false]);
        $kantorOther = Kantor::create(['kode' => 'OTHER', 'nama' => 'Kantor Other']);
        $poi = $this->makePoi($kantorOther);

        $this->actingAs($adminFinal)->get("/poi/{$poi->id}")->assertForbidden();
    }

    public function test_sales_cannot_view_poi_outside_active_kantor(): void
    {
        $sales = User::factory()->create(['force_password_change' => false, 'role' => User::ROLE_SALES]);
        $kantorMine = Kantor::create(['kode' => 'MINE', 'nama' => 'Kantor Mine']);
        $kantorOther = Kantor::create(['kode' => 'OTHER', 'nama' => 'Kantor Other']);
        $sales->kantor()->attach($kantorMine->id);
        $poi = $this->makePoi($kantorOther);

        $this->actingAs($sales)->get("/poi/{$poi->id}")->assertForbidden();
    }

    // ---------------- Create ----------------

    public function test_admin_can_create_poi_for_any_kantor(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $kantor = Kantor::create(['kode' => 'X', 'nama' => 'Kantor X']);

        $response = $this->actingAs($admin)->post('/poi', [
            'nama_poi' => 'Toko Baru',
            'alamat' => 'Jl. Baru No. 1',
            'sektor' => Poi::SEKTOR_OPTIONS[0],
            'kantor_id' => $kantor->id,
            'status_mitra' => Poi::STATUS_MITRA_OPTIONS[0],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('poi', ['nama_poi' => 'Toko Baru', 'kantor_id' => $kantor->id]);
    }

    public function test_admin_final_cannot_create_poi_for_unowned_kantor(): void
    {
        $adminFinal = User::factory()->adminFinal()->create(['force_password_change' => false]);
        $kantorOther = Kantor::create(['kode' => 'OTHER', 'nama' => 'Kantor Other']);

        $response = $this->actingAs($adminFinal)->post('/poi', [
            'nama_poi' => 'Toko Baru',
            'alamat' => 'Jl. Baru No. 1',
            'sektor' => Poi::SEKTOR_OPTIONS[0],
            'kantor_id' => $kantorOther->id,
            'status_mitra' => Poi::STATUS_MITRA_OPTIONS[0],
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('poi', ['nama_poi' => 'Toko Baru']);
    }

    public function test_sales_cannot_create_poi(): void
    {
        $sales = User::factory()->create(['force_password_change' => false, 'role' => User::ROLE_SALES]);
        $kantor = Kantor::create(['kode' => 'X', 'nama' => 'Kantor X']);
        $sales->kantor()->attach($kantor->id);

        $this->actingAs($sales)->get('/poi/create')->assertForbidden();

        $response = $this->actingAs($sales)->post('/poi', [
            'nama_poi' => 'Toko Baru',
            'alamat' => 'Jl. Baru No. 1',
            'sektor' => Poi::SEKTOR_OPTIONS[0],
            'kantor_id' => $kantor->id,
            'status_mitra' => Poi::STATUS_MITRA_OPTIONS[0],
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('poi', ['nama_poi' => 'Toko Baru']);
    }

    // ---------------- Update ----------------

    public function test_admin_final_cannot_edit_poi_of_unowned_kantor(): void
    {
        $adminFinal = User::factory()->adminFinal()->create(['force_password_change' => false]);
        $kantorOther = Kantor::create(['kode' => 'OTHER', 'nama' => 'Kantor Other']);
        $poi = $this->makePoi($kantorOther);

        $this->actingAs($adminFinal)->get("/poi/{$poi->id}/edit")->assertForbidden();

        $response = $this->actingAs($adminFinal)->put("/poi/{$poi->id}", [
            'nama_poi' => 'Diubah',
            'alamat' => $poi->alamat,
            'sektor' => $poi->sektor,
            'kantor_id' => $kantorOther->id,
            'status_mitra' => $poi->status_mitra,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('poi', ['nama_poi' => 'Diubah']);
    }

    public function test_admin_final_cannot_reassign_poi_to_unowned_kantor(): void
    {
        $adminFinal = User::factory()->adminFinal()->create(['force_password_change' => false]);
        $kantorMine = Kantor::create(['kode' => 'MINE', 'nama' => 'Kantor Mine']);
        $kantorOther = Kantor::create(['kode' => 'OTHER', 'nama' => 'Kantor Other']);
        $adminFinal->kantor()->attach($kantorMine->id);
        $poi = $this->makePoi($kantorMine);

        $response = $this->actingAs($adminFinal)->put("/poi/{$poi->id}", [
            'nama_poi' => $poi->nama_poi,
            'alamat' => $poi->alamat,
            'sektor' => $poi->sektor,
            'kantor_id' => $kantorOther->id, // not owned
            'status_mitra' => $poi->status_mitra,
        ]);

        $response->assertForbidden();
        $this->assertSame($kantorMine->id, $poi->fresh()->kantor_id);
    }

    /**
     * Fix (2026-07-15): sektor/area are free text now (see the ENUM->VARCHAR
     * migration and PoiImport's docblock) — the manual edit form used to
     * still enforce Rule::in(Poi::SEKTOR_OPTIONS/AREA_OPTIONS), which meant
     * editing ANY field on an already-imported POI whose sektor/area wasn't
     * one of the 16/4 curated values would fail validation. A value outside
     * those lists must now save successfully, matching what import already
     * allows.
     */
    public function test_admin_can_save_a_sektor_and_area_outside_the_curated_suggestion_list(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $poi = $this->makePoi($kantor);

        $response = $this->actingAs($admin)->put("/poi/{$poi->id}", [
            'nama_poi' => $poi->nama_poi,
            'alamat' => $poi->alamat,
            'sektor' => 'Automotive', // not in Poi::SEKTOR_OPTIONS
            'area' => 'Zona Timur', // not in Poi::AREA_OPTIONS
            'kantor_id' => $kantor->id,
            'status_mitra' => $poi->status_mitra,
        ]);

        $response->assertRedirect(route('poi.edit', $poi));
        $this->assertDatabaseHas('poi', ['id' => $poi->id, 'sektor' => 'Automotive', 'area' => 'Zona Timur']);
    }

    public function test_sales_cannot_update_poi(): void
    {
        $sales = User::factory()->create(['force_password_change' => false, 'role' => User::ROLE_SALES]);
        $kantor = Kantor::create(['kode' => 'X', 'nama' => 'Kantor X']);
        $sales->kantor()->attach($kantor->id);
        $poi = $this->makePoi($kantor);

        $response = $this->actingAs($sales)->put("/poi/{$poi->id}", [
            'nama_poi' => 'Diubah',
            'alamat' => $poi->alamat,
            'sektor' => $poi->sektor,
            'kantor_id' => $kantor->id,
            'status_mitra' => $poi->status_mitra,
        ]);

        $response->assertForbidden();
    }

    // ---------------- Delete (soft toggle) / reopen ----------------

    public function test_delete_requires_reason_and_logs_and_toggles_status(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $kantor = Kantor::create(['kode' => 'X', 'nama' => 'Kantor X']);
        $poi = $this->makePoi($kantor);

        // Missing reason is rejected.
        $this->actingAs($admin)->post("/poi/{$poi->id}/hapus", [])->assertSessionHasErrors('alasan');
        $this->assertSame('aktif', $poi->fresh()->status);

        $response = $this->actingAs($admin)->post("/poi/{$poi->id}/hapus", ['alasan' => 'Duplikat data']);

        $response->assertRedirect(route('poi.index'));
        $poi->refresh();
        $this->assertSame('nonaktif', $poi->status);
        $this->assertDatabaseHas('poi_reopen_log', [
            'poi_id' => $poi->id,
            'action' => 'hapus',
            'alasan' => 'Duplikat data',
            'user_id' => $admin->id,
        ]);
    }

    public function test_reopen_requires_reason_and_logs_and_toggles_status(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $kantor = Kantor::create(['kode' => 'X', 'nama' => 'Kantor X']);
        $poi = $this->makePoi($kantor, ['status' => 'nonaktif']);

        $this->actingAs($admin)->post("/poi/{$poi->id}/reopen", [])->assertSessionHasErrors('alasan');

        $response = $this->actingAs($admin)->post("/poi/{$poi->id}/reopen", ['alasan' => 'Data valid kembali']);

        $response->assertRedirect(route('poi.edit', $poi));
        $poi->refresh();
        $this->assertSame('aktif', $poi->status);
        $this->assertDatabaseHas('poi_reopen_log', [
            'poi_id' => $poi->id,
            'action' => 'reopen',
            'alasan' => 'Data valid kembali',
            'user_id' => $admin->id,
        ]);
    }

    public function test_admin_final_cannot_delete_poi_of_unowned_kantor(): void
    {
        $adminFinal = User::factory()->adminFinal()->create(['force_password_change' => false]);
        $kantorOther = Kantor::create(['kode' => 'OTHER', 'nama' => 'Kantor Other']);
        $poi = $this->makePoi($kantorOther);

        $response = $this->actingAs($adminFinal)->post("/poi/{$poi->id}/hapus", ['alasan' => 'Coba hapus']);

        $response->assertForbidden();
        $this->assertSame('aktif', $poi->fresh()->status);
    }

    public function test_sales_cannot_delete_poi(): void
    {
        $sales = User::factory()->create(['force_password_change' => false, 'role' => User::ROLE_SALES]);
        $kantor = Kantor::create(['kode' => 'X', 'nama' => 'Kantor X']);
        $sales->kantor()->attach($kantor->id);
        $poi = $this->makePoi($kantor);

        $response = $this->actingAs($sales)->post("/poi/{$poi->id}/hapus", ['alasan' => 'Coba hapus']);

        $response->assertForbidden();
        $this->assertSame('aktif', $poi->fresh()->status);
    }

    // ---------------- Observer / dashboard_summary integrity ----------------

    public function test_creating_editing_and_deleting_poi_keeps_dashboard_summary_in_sync(): void
    {
        $admin = User::factory()->admin()->create(['force_password_change' => false]);
        $kantor = Kantor::create(['kode' => 'X', 'nama' => 'Kantor X']);

        $this->assertDatabaseCount('dashboard_summary', 0);

        $poi = $this->makePoi($kantor);

        $row = DashboardSummary::where('kantor_id', $kantor->id)->first();
        $this->assertNotNull($row, 'dashboard_summary row should be created via PoiObserver on Poi::create()');
        $this->assertSame(1, $row->total_poi);

        $this->actingAs($admin)->post("/poi/{$poi->id}/hapus", ['alasan' => 'Nonaktifkan untuk tes']);

        $row->refresh();
        $this->assertSame(0, $row->total_poi, 'dashboard_summary should decrement when POI goes nonaktif');

        $this->actingAs($admin)->post("/poi/{$poi->id}/reopen", ['alasan' => 'Aktifkan lagi untuk tes']);

        $row->refresh();
        $this->assertSame(1, $row->total_poi, 'dashboard_summary should increment again on reopen');
    }

    /**
     * Fix (2026-07-15): no current PoiController route combines a status
     * (aktif<->nonaktif) change with a kantor_id/status_mitra change in the
     * same update() call, but PoiObserver::updated() must still handle it
     * correctly for any future bulk-edit/API path — exercised directly here
     * against the model since there's no UI path to drive it through yet.
     * Deactivating must decrement the ORIGINAL kantor (where the POI was
     * actually counted while aktif), not the newly-assigned one.
     */
    public function test_poi_observer_deactivating_while_reassigning_kantor_decrements_the_original_kantor(): void
    {
        $kantorA = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $kantorB = Kantor::create(['kode' => 'B', 'nama' => 'Kantor B']);
        $poi = $this->makePoi($kantorA);

        $rowA = DashboardSummary::where('kantor_id', $kantorA->id)->first();
        $rowB = DashboardSummary::where('kantor_id', $kantorB->id)->first();
        $this->assertSame(1, $rowA->total_poi);
        $this->assertNull($rowB);

        // Deactivate AND reassign kantor in the same save.
        $poi->update(['status' => 'nonaktif', 'kantor_id' => $kantorB->id]);

        $rowA->refresh();
        $rowB = DashboardSummary::where('kantor_id', $kantorB->id)->first();
        $this->assertSame(0, $rowA->total_poi, 'Kantor A should be decremented — the POI was counted there while aktif.');
        $this->assertSame(0, $rowB->total_poi ?? 0, 'Kantor B must NOT be touched — the POI was never counted there while aktif.');
    }

    /**
     * Mirror of the above for the reactivation direction: reactivating while
     * also reassigning kantor must increment the NEW kantor (where it will
     * actually be counted going forward), not the one it was nonaktif under.
     */
    public function test_poi_observer_reactivating_while_reassigning_kantor_increments_the_new_kantor(): void
    {
        $kantorA = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $kantorB = Kantor::create(['kode' => 'B', 'nama' => 'Kantor B']);
        $poi = $this->makePoi($kantorA, ['status' => 'nonaktif']);

        $rowA = DashboardSummary::where('kantor_id', $kantorA->id)->first();
        $this->assertNull($rowA, 'A nonaktif POI created straight into that state should not be counted anywhere.');

        $poi->update(['status' => 'aktif', 'kantor_id' => $kantorB->id]);

        $rowA = DashboardSummary::where('kantor_id', $kantorA->id)->first();
        $rowB = DashboardSummary::where('kantor_id', $kantorB->id)->first();
        $this->assertSame(0, $rowA->total_poi ?? 0, 'Kantor A must NOT be touched — the POI was never counted there.');
        $this->assertSame(1, $rowB->total_poi, 'Kantor B should be incremented — that is where it is counted now.');
    }
}
