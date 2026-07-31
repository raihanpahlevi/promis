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

  {{-- Three produk histograms, stacked full width (2026-07-30, replacing the
       two donut charts). Each produk shows Ditawarkan vs Closing side by side;
       the groups have 3 / 6 / 9 produk, so each needs the whole row for its
       labels to stay readable. --}}
  @foreach ($produkGrup as $i => $grup)
    <div class="panel" style="margin-bottom:16px">
      <div class="panel-head">
        <h3>{{ $grup['judul'] }}</h3>
        <span class="badge" style="background:var(--brand-50);color:var(--brand-700)">
          {{ number_format($grup['total_closing']) }} / {{ number_format($grup['total_ditawarkan']) }} closing
          &middot; {{ $grup['closing_rate'] }}%
        </span>
      </div>
      @if ($grup['total_ditawarkan'] === 0)
        <div class="empty-state-rich">
          <i class="bi bi-bar-chart"></i>
          <p>Belum ada produk {{ $grup['judul'] }} yang ditawarkan pada rentang ini.</p>
          <small>Coba ubah rentang tanggal atau filter cabang di atas.</small>
        </div>
      @else
        <div class="chart-lg"><canvas id="produkGrup{{ $i }}"></canvas></div>
      @endif
    </div>
  @endforeach

  @push('scripts')
  <script>
    Chart.register(ChartDataLabels);
    new Chart(document.getElementById('histogramChart'), {
      type: 'bar',
      data: {
        labels: @json($histogram['labels']),
        datasets: [
          {label: 'Closing', data: @json($histogram['closing']), backgroundColor: '#2E7D32'},
          {label: 'Ditawarkan', data: @json($histogram['non_closing']), backgroundColor: '#C62828'}
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

    // Measured from the element's own geometry: BarElement in Chart.js v4 has no
    // `.height` property (an earlier cut read it and always got undefined, so
    // every label fell back to sitting above the bar — where a full-height bar
    // pushed it outside the chart area and it vanished). y..base is the real
    // drawn extent.
    function tinggiBalok(ctx) {
      var el = ctx.chart.getDatasetMeta(ctx.datasetIndex).data[ctx.dataIndex];
      if (!el) return 0;
      var p = el.getProps(['y', 'base'], true);
      return Math.abs(p.base - p.y);
    }

    // Bars packed tight with the figure printed inside the bar (client's ask):
    // categoryPercentage/barPercentage near 1 removes the gap Chart.js leaves by
    // default, and the datalabel is centre-anchored in white. A bar too short to
    // hold text falls back to sitting just above it in brand ink.
@foreach ($produkGrup as $i => $grup)
@if ($grup['total_ditawarkan'] > 0)
    new Chart(document.getElementById('produkGrup{{ $i }}'), {
      type: 'bar',
      data: {
        labels: @json($grup['labels']),
        datasets: [
          {label: 'Ditawarkan', data: @json($grup['ditawarkan']), backgroundColor: '#C62828', borderRadius: 4, borderSkipped: false},
          {label: 'Closing', data: @json($grup['closing']), backgroundColor: '#2E7D32', borderRadius: 4, borderSkipped: false}
        ]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        categoryPercentage: 0.92,
        barPercentage: 0.96,
        layout: {padding: {top: 14}},
        plugins: {
          legend: {position: 'bottom'},
          datalabels: {
            color: function (ctx) { return tinggiBalok(ctx) >= 18 ? '#fff' : '#3E2723'; },
            anchor: function (ctx) { return tinggiBalok(ctx) >= 18 ? 'center' : 'end'; },
            align: function (ctx) { return tinggiBalok(ctx) >= 18 ? 'center' : 'top'; },
            clamp: true,
            font: {weight: 'bold', size: 11},
            formatter: function (v) { return v > 0 ? v : ''; }
          }
        },
        scales: {
          x: {grid: {display: false}},
          y: {beginAtZero: true, ticks: {precision: 0}}
        }
      }
    });
@endif
@endforeach
  </script>
  @endpush
@endsection
