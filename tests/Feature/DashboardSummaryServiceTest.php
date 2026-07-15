<?php

namespace Tests\Feature;

use App\Models\DashboardSummary;
use App\Models\Kantor;
use App\Services\DashboardSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * All six dashboard_summary counter columns are `unsignedInteger` — a
 * negative value throws a hard SQL error under MySQL strict mode rather
 * than just being a wrong number. These tests confirm the 2026-07-15 floor
 * guard (DashboardSummaryService::applyDeltas()) actually holds even when
 * a caller pushes a counter past zero, instead of relying on every caller
 * to never do that.
 */
class DashboardSummaryServiceTest extends TestCase
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

    public function test_adjust_poi_never_lets_a_counter_go_below_zero(): void
    {
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $service = app(DashboardSummaryService::class);

        // Starting from an empty (auto-seeded) row, decrementing further
        // must floor at 0 rather than going negative.
        $service->adjustPoi($kantor->id, 'Bukan Nasabah BNI', -1);

        $row = DashboardSummary::where('kantor_id', $kantor->id)->where('tanggal', now()->toDateString())->first();
        $this->assertSame(0, $row->total_poi);
        $this->assertSame(0, $row->poi_bukan_nasabah);
    }

    public function test_reverse_kunjungan_never_lets_a_counter_go_below_zero(): void
    {
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $service = app(DashboardSummaryService::class);

        // Reverse without a matching prior recordKunjungan() — simulates
        // drift (e.g. a double-reopen race) rather than crashing.
        $service->reverseKunjungan($kantor->id, true);

        $row = DashboardSummary::where('kantor_id', $kantor->id)->where('tanggal', now()->toDateString())->first();
        $this->assertSame(0, $row->total_kunjungan);
        $this->assertSame(0, $row->total_closing);
    }

    public function test_normal_increment_and_decrement_still_nets_out_correctly(): void
    {
        $kantor = Kantor::create(['kode' => 'A', 'nama' => 'Kantor A']);
        $service = app(DashboardSummaryService::class);

        $service->adjustPoi($kantor->id, 'Nasabah Merchant BNI', 1);
        $service->adjustPoi($kantor->id, 'Nasabah Merchant BNI', 1);
        $service->adjustPoi($kantor->id, 'Nasabah Merchant BNI', -1);

        $row = DashboardSummary::where('kantor_id', $kantor->id)->where('tanggal', now()->toDateString())->first();
        $this->assertSame(1, $row->total_poi);
        $this->assertSame(1, $row->poi_merchant);
    }
}
