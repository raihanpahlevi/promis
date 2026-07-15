@extends('layouts.app')

@section('title', 'Rekap Sales')
@section('breadcrumb', 'Laporan / Rekap Sales')

@push('head')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
@endpush

@section('content')
  @include('laporan._tabs')

  <form method="GET" action="{{ route('laporan.rekap-sales') }}" style="margin-bottom:16px">
    <input type="hidden" name="mode" value="{{ $mode }}">
    <div class="filters" style="flex-wrap:wrap">
      <input type="date" name="dari" value="{{ $dari }}" style="padding:7px 10px;border-radius:8px;border:1px solid var(--brand-100)">
      <input type="date" name="sampai" value="{{ $sampai }}" style="padding:7px 10px;border-radius:8px;border:1px solid var(--brand-100)">
      <select name="unit">
        <option value="">Semua Unit</option>
        @foreach ($unitOptions as $unit)
          <option value="{{ $unit->id }}" @selected($unitId === $unit->id)>{{ $unit->nama }}</option>
        @endforeach
      </select>
      @if ($kantorOptions->isNotEmpty())
        <select name="kantor">
          <option value="">{{ auth()->user()->isAdmin() ? 'Semua Kantor' : 'Semua Kantor Saya' }}</option>
          @foreach ($kantorOptions as $kantor)
            <option value="{{ $kantor->id }}" @selected($selectedKantorId === $kantor->id)>{{ $kantor->nama }}</option>
          @endforeach
        </select>
      @endif
      <button type="submit" class="btn-primary-custom" style="padding:7px 16px;font-size:12px;width:auto">Terapkan</button>
    </div>
  </form>

  @php
    $carryQuery = ['dari' => $dari, 'sampai' => $sampai, 'unit' => $unitId, 'kantor' => $selectedKantorId];
  @endphp
  <div class="section-tabs">
    <a href="{{ route('laporan.rekap-sales', array_filter($carryQuery + ['mode' => 'kunjungan'])) }}" class="{{ $mode === 'kunjungan' ? 'active' : '' }}">Kunjungan</a>
    <a href="{{ route('laporan.rekap-sales', array_filter($carryQuery + ['mode' => 'tidak'])) }}" class="{{ $mode === 'tidak' ? 'active' : '' }}">Tidak Kunjungan</a>
  </div>

  <div class="panel" style="margin-bottom:16px">
    <div class="panel-head"><h3>Histogram Visit POI</h3></div>
    <div class="chart-lg"><canvas id="histUser"></canvas></div>
  </div>

  <div class="panel" style="margin-bottom:16px">
    <div class="panel-head"><h3>Histogram Per Kantor{{ $unitId ? ' ('.$unitOptions->firstWhere('id', $unitId)?->nama.')' : '' }}</h3></div>
    <div class="chart-lg"><canvas id="histKantor"></canvas></div>
  </div>

  <div class="table-panel">
    @if ($mode === 'kunjungan')
      <div class="panel-head"><h3>Rekap Kunjungan per Sales</h3></div>
      @if ($kunjunganRows->isEmpty())
        <div class="empty-state-rich">
          <i class="bi bi-clipboard-x"></i>
          <p>Tidak ada sales dengan kunjungan pada rentang &amp; filter ini.</p>
          <small>Coba lebarkan rentang tanggal atau ganti filter unit/kantor.</small>
        </div>
      @else
        <div style="overflow-x:auto">
          <table class="table-ledger">
            <thead>
              <tr><th>Nama Sales</th><th>Unit</th><th>Kantor</th><th class="num" style="text-align:right">Total Visit</th><th class="num" style="text-align:right">Total Closing</th></tr>
            </thead>
            <tbody>
              @foreach ($kunjunganRows as $u)
                <tr>
                  <td>{{ $u->nama_lengkap }}</td>
                  <td>{{ $u->unit->nama ?? '-' }}</td>
                  <td>{{ $u->kantor->pluck('nama')->join(', ') ?: '-' }}</td>
                  <td class="num" style="text-align:right"><strong>{{ $u->total_visit }}</strong></td>
                  <td class="num" style="text-align:right">{{ $u->total_closing }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif
    @else
      <div class="panel-head"><h3>Sales Belum Kunjungan</h3></div>
      @if ($tidakRows->isEmpty())
        <div class="empty-state-rich">
          <i class="bi bi-emoji-smile"></i>
          <p>Semua sales pada cakupan ini sudah kunjungan di rentang tanggal tersebut.</p>
          <small>Tidak ada tindak lanjut yang perlu dikejar untuk filter ini.</small>
        </div>
      @else
        <div style="overflow-x:auto">
          <table class="table-ledger">
            <thead>
              <tr><th>Nama Sales</th><th>Unit</th><th>Kantor</th><th>Status</th></tr>
            </thead>
            <tbody>
              @foreach ($tidakRows as $u)
                <tr>
                  <td>{{ $u->nama_lengkap }}</td>
                  <td>{{ $u->unit->nama ?? '-' }}</td>
                  <td>{{ $u->kantor->pluck('nama')->join(', ') ?: '-' }}</td>
                  <td><span class="badge badge-no">Tidak Kunjungan</span></td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif
    @endif
  </div>

  @push('scripts')
  <script>
    Chart.register(ChartDataLabels);

    new Chart(document.getElementById('histUser'), {
      type: 'bar',
      data: {
        labels: @json($histogram['labels']),
        datasets: [
          {label: 'Kunjungan', data: @json($histogram['kunjungan']), backgroundColor: '#2E7D32'},
          {label: 'Closing', data: @json($histogram['closing']), backgroundColor: '#1565C0'},
          {label: 'Ada Belum Kunjungan', data: @json($histogram['marker']), backgroundColor: '#C62828', barPercentage: 0.4, categoryPercentage: 0.5, datalabels: {display: false}}
        ]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
          legend: {position: 'bottom'},
          tooltip: {callbacks: {label: (ctx) => ctx.dataset.label === 'Ada Belum Kunjungan' ? null : ctx.dataset.label + ': ' + ctx.raw}},
          datalabels: {anchor: 'end', align: 'top', color: '#3E2723', font: {weight: 'bold', size: 11}, formatter: (v, ctx) => ctx.dataset.label === 'Ada Belum Kunjungan' ? null : (v > 0 ? v : '')}
        },
        scales: {y: {beginAtZero: true, ticks: {precision: 0}}}
      }
    });

    new Chart(document.getElementById('histKantor'), {
      type: 'bar',
      data: {
        labels: @json($histogram['labels2']),
        datasets: [
          {label: 'Kunjungan', data: @json($histogram['kunjungan2']), backgroundColor: '#2E7D32'},
          {label: 'Tidak Kunjungan', data: @json($histogram['tidak2']), backgroundColor: '#C62828'},
          {label: 'Closing', data: @json($histogram['closing2']), backgroundColor: '#1565C0'}
        ]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
          legend: {position: 'bottom'},
          datalabels: {anchor: 'end', align: 'top', color: '#3E2723', font: {weight: 'bold', size: 11}, formatter: v => v > 0 ? v : ''}
        },
        scales: {y: {beginAtZero: true, ticks: {precision: 0}}}
      }
    });
  </script>
  @endpush
@endsection
