@extends('layouts.app')

@section('title', 'Summary Produk')
@section('breadcrumb', 'Laporan / Summary Produk')

@section('content')
  @include('laporan._tabs')

  <div class="stack-lg">
    @include('laporan._summary-filter', ['formAction' => route('laporan.summary-produk')])

    <div class="table-panel">
      <div class="panel-head">
        <h3>Summary Produk Closing per Cabang</h3>
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
        {{-- Scrolls on both axes so the sticky header has something to
             stick to; with overflow-x only, the wrapper never scrolled
             vertically and position:sticky was a no-op. --}}
        <div class="table-summary-scroll">
          <table class="table-ledger table-summary">
            <thead>
              <tr>
                <th rowspan="2">Report Product</th>
                <th colspan="{{ count($produkList) }}" style="text-align:center">Produk Closing</th>
                <th rowspan="2" class="num">Total</th>
              </tr>
              <tr>
                @foreach ($produkList as $produk)
                  <th class="num">{{ $produk }}</th>
                @endforeach
              </tr>
            </thead>
            <tbody>
              @foreach ($rows as $row)
                @php($totalProduk = array_sum(array_intersect_key($row['values'], array_flip($produkList))))
                <tr class="row-{{ $row['level'] }}">
                  <td class="cell-heading">{{ $row['label'] }}</td>
                  @foreach ($produkList as $produk)
                    <td class="num">{{ number_format($row['values'][$produk] ?? 0) }}</td>
                  @endforeach
                  <td class="num"><strong>{{ number_format($totalProduk) }}</strong></td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif
    </div>
  </div>
@endsection
