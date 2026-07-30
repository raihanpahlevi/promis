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
        <span class="badge" style="background:var(--brand-50);color:var(--brand-700)">
          {{ \Carbon\Carbon::parse($dari)->format('d M Y') }} &ndash; {{ \Carbon\Carbon::parse($sampai)->format('d M Y') }}
        </span>
      </div>

      @if (count($rows) <= 1)
        <div class="empty-state-rich">
          <i class="bi bi-table"></i>
          <p>Belum ada Cabang pada filter ini.</p>
          <small>Coba lebarkan filter Area/Cluster/Cabang di atas.</small>
        </div>
      @else
        <div style="overflow-x:auto">
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
                @php
                  $totalKunjungan = 0;
                  foreach ($stages as $s) { $totalKunjungan += $row['values'][$s] ?? 0; }
                  $closing = $row['values'][\App\Models\Kunjungan::HASIL_CLOSING] ?? 0;
                  $poi = $row['values']['jumlah_poi'] ?? 0;
                  // Basis sengaja Jumlah POI (bukan total kunjungan) — ikut rumus
                  // di file sumbernya: "berapa persen POI yang sudah closing".
                  $persen = $poi > 0 ? round($closing / $poi * 100, 2) : null;
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
