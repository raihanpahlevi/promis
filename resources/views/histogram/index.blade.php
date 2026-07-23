@extends('layouts.app')

@section('title', 'Dashboard Admin')
@section('breadcrumb', 'Laporan / Histogram')

@push('head')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
@endpush

@section('content')
  <form method="GET" action="{{ route('histogram.index') }}" style="margin-bottom:16px">
    <div class="filters" style="flex-wrap:wrap">
      <input type="date" name="dari" value="{{ $dari }}" style="padding:9px 10px;border-radius:8px;border:1px solid var(--brand-100);font-size:16px">
      <input type="date" name="sampai" value="{{ $sampai }}" style="padding:9px 10px;border-radius:8px;border:1px solid var(--brand-100);font-size:16px">
      @if ($kantorAreaOptions->isNotEmpty())
        <select name="area">
          <option value="">Semua Area</option>
          @foreach ($kantorAreaOptions as $kantorArea)
            <option value="{{ $kantorArea }}" @selected($selectedKantorArea === $kantorArea)>{{ $kantorArea }}</option>
          @endforeach
        </select>
      @endif
      @if ($kantorClusterOptions->isNotEmpty())
        <select name="cluster">
          <option value="">Semua Cabang-Cluster</option>
          @foreach ($kantorClusterOptions as $kantorCluster)
            <option value="{{ $kantorCluster }}" @selected($selectedKantorCluster === $kantorCluster)>{{ $kantorCluster }}</option>
          @endforeach
        </select>
      @endif
      @if ($kantorOptions->isNotEmpty())
        <select name="kantor" @disabled($kantorLocked)>
          @unless ($kantorLocked)
            <option value="">{{ auth()->user()->isAdmin() ? 'Semua Cabang' : 'Semua Cabang Saya' }}</option>
          @endunless
          @foreach ($kantorOptions as $kantor)
            <option value="{{ $kantor->id }}" @selected($selectedKantorId === $kantor->id)>{{ $kantor->nama }}</option>
          @endforeach
        </select>
        @if ($kantorLocked)
          <input type="hidden" name="kantor" value="{{ $selectedKantorId }}">
        @endif
      @endif
      <button type="submit" class="btn-primary-custom" style="padding:10px 16px;font-size:13px;width:auto">Terapkan</button>
    </div>
  </form>

  <div class="panel" style="margin-bottom:16px">
    <div class="panel-head"><h3>Histogram Akuisisi</h3></div>
    <div class="chart-lg"><canvas id="histogramChart"></canvas></div>
  </div>

  <div class="grid-2" style="margin-bottom:16px">
    <div class="panel">
      <div class="panel-head"><h3>Produk Ditawarkan</h3></div>
      <div class="chart-mini"><canvas id="pieAllChart"></canvas></div>
    </div>
    <div class="panel">
      <div class="panel-head">
        <h3>Closing Rate Produk <span class="closing-rate-badge">({{ $closingRate }}%)</span></h3>
      </div>
      <p style="font-size:11px;color:#8A6B55;margin:-8px 0 0">Total produk closing / total produk ditawarkan.</p>
      <div class="chart-mini"><canvas id="pieClosingChart"></canvas></div>
    </div>
  </div>

  <div class="table-panel">
    <div class="panel-head"><h3>Detail Akuisisi (Closing)</h3></div>
    @if ($detail->isEmpty())
      <div class="empty-state-rich">
        <i class="bi bi-inboxes"></i>
        <p>Tidak ada data closing pada rentang ini.</p>
        <small>Coba ubah rentang tanggal atau filter kantor di atas.</small>
      </div>
    @else
      <div style="overflow-x:auto">
        <table class="table-ledger table-responsive-stack">
          <thead>
            <tr><th>Nama POI</th><th>Tanggal</th><th>Kantor</th><th>Produk</th></tr>
          </thead>
          <tbody>
            @foreach ($detail as $row)
              <tr>
                <td class="cell-heading">{{ $row->poi->nama_poi ?? '-' }}</td>
                <td data-label="Tanggal">{{ $row->tanggal_kunjungan->format('d M Y') }}</td>
                <td data-label="Kantor">{{ $row->poi->kantor->nama ?? '-' }}</td>
                <td data-label="Produk">{{ $row->produkList->pluck('produk')->implode(', ') ?: '-' }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>

      @include('partials.pagination', ['paginator' => $detail])
    @endif
  </div>

  @push('scripts')
  <script>
    Chart.register(ChartDataLabels);
    Chart.register({
      id: 'centerText',
      afterDraw(chart) {
        if (chart.config.type !== 'doughnut') return;
        var ctx = chart.ctx, area = chart.chartArea;
        if (!area) return;
        var total = chart.data.datasets[0].data.reduce((a, b) => a + b, 0);
        var x = (area.left + area.right) / 2, y = (area.top + area.bottom) / 2;
        ctx.save();
        ctx.font = 'bold 18px Inter';
        ctx.fillStyle = '#3E2723';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(total, x, y);
        ctx.restore();
      }
    });

    new Chart(document.getElementById('histogramChart'), {
      type: 'bar',
      data: {
        labels: @json($histogram['labels']),
        datasets: [
          {label: 'Closing', data: @json($histogram['closing']), backgroundColor: '#2E7D32'},
          {label: 'Belum Closing', data: @json($histogram['non_closing']), backgroundColor: '#C62828'}
        ]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
          legend: {position: 'bottom'},
          datalabels: {color: '#3E2723', anchor: 'end', align: 'end', font: {weight: 'bold', size: 11}, formatter: v => v > 0 ? v : ''}
        },
        scales: {y: {beginAtZero: true, ticks: {precision: 0}}}
      }
    });

    new Chart(document.getElementById('pieAllChart'), {
      type: 'doughnut',
      data: {
        labels: @json(array_keys($produkAll)),
        datasets: [{data: @json(array_values($produkAll)), backgroundColor: ['#6F4E37','#A47148','#2E7D32','#1565C0','#B26A00','#8D6748','#C62828','#4E342E']}]
      },
      options: {cutout: '65%', maintainAspectRatio: false, plugins: {legend: {position: 'bottom', labels: {font: {size: 10}}}}}
    });

    new Chart(document.getElementById('pieClosingChart'), {
      type: 'doughnut',
      data: {
        labels: @json(array_keys($produkClosing)),
        datasets: [{data: @json(array_values($produkClosing)), backgroundColor: ['#2E7D32','#A47148','#1565C0','#C62828','#6F4E37','#8D6748']}]
      },
      options: {cutout: '65%', maintainAspectRatio: false, plugins: {legend: {position: 'bottom', labels: {font: {size: 10}}}}}
    });
  </script>
  @endpush
@endsection
