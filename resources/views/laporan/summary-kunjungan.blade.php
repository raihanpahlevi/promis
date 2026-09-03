@extends('layouts.app')

@section('title', 'Summary Kunjungan')
@section('breadcrumb', 'Laporan / Summary Kunjungan')

@section('content')
  @include('laporan._tabs')

  <div class="stack-lg">
    @include('laporan._summary-filter', ['formAction' => route('laporan.summary-kunjungan')])

    <div class="table-panel">
      <div class="panel-head">
        <h3>Summary Kunjungan per Cabang</h3>
        <div style="display:flex;align-items:center;gap:10px;margin-left:auto">
          <span class="badge" style="background:var(--brand-50);color:var(--brand-700)">
            {{ \Carbon\Carbon::parse($dari)->format('d M Y') }} &ndash; {{ \Carbon\Carbon::parse($sampai)->format('d M Y') }}
          </span>
          @if (count($rows) > 1)
            {{-- request()->query() so the file carries whatever filter the user
                 is currently looking at, chip pickers included. --}}
            <a href="{{ route('laporan.summary-kunjungan.export', request()->query()) }}"
               class="btn-primary-custom" style="text-decoration:none;padding:8px 16px;width:auto;font-size:12.5px;white-space:nowrap">
              <i class="bi bi-file-earmark-excel"></i> Export Excel
            </a>
          @endif
        </div>
      </div>

      @if (count($rows) <= 1)
        <div class="empty-state-rich">
          <i class="bi bi-table"></i>
          <p>Belum ada Cabang pada filter ini.</p>
          <small>Coba lebarkan filter Area/Cluster/Cabang di atas.</small>
        </div>
      @else
        {{-- Scrolls on both axes so the sticky header has something to
             stick to; with overflow-x only, the wrapper never scrolled
             vertically and position:sticky was a no-op. --}}
        <div class="table-summary-scroll">
          <table class="table-ledger table-summary">
            <thead>
              <tr>
                <th rowspan="2">Report Sales</th>
                <th rowspan="2" class="num">Jumlah Sales</th>
                <th rowspan="2" class="num">Jumlah POI</th>
                <th colspan="{{ count($stages) + 1 }}" style="text-align:center">Hasil Kunjungan</th>
                <th rowspan="2" class="num">%-Tase Closing</th>
              </tr>
              <tr>
                @foreach ($stages as $stage)
                  <th class="num">{{ $stage }}</th>
                @endforeach
                <th class="num">Total Kunjungan</th>
              </tr>
            </thead>
            <tbody>
              @foreach ($rows as $row)
                {{-- Total Kunjungan & %-Tase Closing dihitung di
                     LaporanController::summaryKunjunganData() supaya layar dan
                     file Excel-nya tidak mungkin beda angka. --}}
                @php
                  $totalKunjungan = $row['values']['total_kunjungan'] ?? 0;
                  $poi = $row['values']['jumlah_poi'] ?? 0;
                  $persen = $row['values']['persen_closing'] ?? null;
                @endphp
                <tr class="row-{{ $row['level'] }}">
                  <td class="cell-heading">{{ $row['label'] }}</td>
                  <td class="num">{{ number_format($row['values']['jumlah_sales'] ?? 0) }}</td>
                  <td class="num">{{ number_format($poi) }}</td>
                  @foreach ($stages as $stage)
                    <td class="num">{{ number_format($row['values'][$stage] ?? 0) }}</td>
                  @endforeach
                  <td class="num"><strong>{{ number_format($totalKunjungan) }}</strong></td>
                  <td class="num">{{ $persen === null ? '-' : $persen.'%' }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif
    </div>
  </div>
@endsection
