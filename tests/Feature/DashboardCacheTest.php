<?php

namespace Tests\Feature;

use App\Models\Kantor;
use App\Models\Poi;
use App\Models\User;
use App\Services\DashboardCache;
use Database\Factories\PoiFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Dashboard's POI breakdowns are cached for 5 minutes because recomputing
 * them per request made the page collapse under concurrency (production,
 * 2026-08-02: ~1s alone, 8.4s median with twenty people on it). These tests
 * pin the three properties that make that safe: a repeat view costs no
 * queries, two different kantor scopes never share an entry, and finishing an
 * import retires everything so a bulk load shows up at once.
 */
class DashboardCacheTest extends TestCase
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

    private function countPoiAggregateQueries(callable $work): int
    {
        $n = 0;
        DB::listen(function ($event) use (&$n) {
            // Quote characters differ between the sqlite test driver and MySQL,
            // so strip them rather than matching one dialect's spelling. The
            // kunjungan funnel filters by kantor through a whereHas subquery
            // that also mentions poi — exclude it, or it reads as an uncached
            // POI aggregate when it is neither (kunjungan is period-scoped and
            // small, and is deliberately left uncached).
            $sql = strtolower(str_replace(['`', '"'], '', $event->sql));
            if (str_contains($sql, 'from poi')
                && str_contains($sql, 'group by')
                && ! str_contains($sql, 'from kunjungan')) {
                $n++;
            }
        });

        $work();

        return $n;
    }

    public function test_a_second_view_reuses_the_cached_breakdowns(): void
    {
        $kantor = Kantor::create(['kode' => 'K1', 'nama' => 'Kantor Satu', 'area' => 'AREA', 'cabang_cluster' => 'CL']);
        PoiFactory::new()->count(3)->create(['kantor_id' => $kantor->id, 'status' => 'aktif']);
        $admin = User::factory()->admin()->create(['force_password_change' => false]);

        $first = $this->countPoiAggregateQueries(function () use ($admin) {
            $this->actingAs($admin)->get('/dashboard')->assertOk();
        });
        $second = $this->countPoiAggregateQueries(function () use ($admin) {
            $this->actingAs($admin)->get('/dashboard')->assertOk();
        });

        $this->assertGreaterThan(0, $first, 'Tampilan pertama harus benar-benar menghitung.');
        $this->assertSame(0, $second, 'Tampilan kedua harus dilayani dari cache, bukan menghitung ulang.');
    }

    public function test_different_kantor_scopes_do_not_share_a_cache_entry(): void
    {
        $satu = Kantor::create(['kode' => 'K1', 'nama' => 'Kantor Satu', 'area' => 'AREA', 'cabang_cluster' => 'CL']);
        $dua = Kantor::create(['kode' => 'K2', 'nama' => 'Kantor Dua', 'area' => 'AREA', 'cabang_cluster' => 'CL']);
        PoiFactory::new()->count(5)->create(['kantor_id' => $satu->id, 'status' => 'aktif', 'sektor' => 'Education']);
        PoiFactory::new()->count(2)->create(['kantor_id' => $dua->id, 'status' => 'aktif', 'sektor' => 'Education']);

        $admin = User::factory()->admin()->create(['force_password_change' => false]);

        $a = $this->actingAs($admin)->get('/dashboard?kantor[]='.$satu->id)->viewData('sektor');
        $b = $this->actingAs($admin)->get('/dashboard?kantor[]='.$dua->id)->viewData('sektor');

        $this->assertSame(5, collect($a['bni'])->firstWhere('sektor', 'Education')['total']
            + collect($a['non'])->firstWhere('sektor', 'Education')['total']);
        $this->assertSame(2, collect($b['bni'])->firstWhere('sektor', 'Education')['total']
            + collect($b['non'])->firstWhere('sektor', 'Education')['total'],
            'Cakupan kantor kedua tidak boleh kebagian angka milik yang pertama.');
    }

    public function test_flush_makes_the_next_view_recompute(): void
    {
        $kantor = Kantor::create(['kode' => 'K1', 'nama' => 'Kantor Satu', 'area' => 'AREA', 'cabang_cluster' => 'CL']);
        PoiFactory::new()->count(2)->create(['kantor_id' => $kantor->id, 'status' => 'aktif']);
        $admin = User::factory()->admin()->create(['force_password_change' => false]);

        $this->actingAs($admin)->get('/dashboard')->assertOk();
        app(DashboardCache::class)->flush();

        $after = $this->countPoiAggregateQueries(function () use ($admin) {
            $this->actingAs($admin)->get('/dashboard')->assertOk();
        });

        $this->assertGreaterThan(0, $after, 'Setelah flush, angkanya harus dihitung ulang.');
    }

    /**
     * The point of flushing on import: without it a bulk load would sit behind
     * the TTL and the team would read pre-import numbers.
     */
    public function test_newly_imported_poi_show_up_once_the_cache_is_flushed(): void
    {
        $kantor = Kantor::create(['kode' => 'K1', 'nama' => 'Kantor Satu', 'area' => 'AREA', 'cabang_cluster' => 'CL']);
        PoiFactory::new()->create(['kantor_id' => $kantor->id, 'status' => 'aktif', 'sektor' => 'Education']);
        $admin = User::factory()->admin()->create(['force_password_change' => false]);

        $before = $this->actingAs($admin)->get('/dashboard')->viewData('sektor');
        $this->assertSame(1, collect($before['bni'])->firstWhere('sektor', 'Education')['total']
            + collect($before['non'])->firstWhere('sektor', 'Education')['total']);

        PoiFactory::new()->count(4)->create(['kantor_id' => $kantor->id, 'status' => 'aktif', 'sektor' => 'Education']);

        // Still the old figure — that is the cache doing its job.
        $stale = $this->actingAs($admin)->get('/dashboard')->viewData('sektor');
        $this->assertSame(1, collect($stale['bni'])->firstWhere('sektor', 'Education')['total']
            + collect($stale['non'])->firstWhere('sektor', 'Education')['total']);

        app(DashboardCache::class)->flush();

        $fresh = $this->actingAs($admin)->get('/dashboard')->viewData('sektor');
        $this->assertSame(5, collect($fresh['bni'])->firstWhere('sektor', 'Education')['total']
            + collect($fresh['non'])->firstWhere('sektor', 'Education')['total']);
    }
}
