@extends('layouts.app')

@section('title', 'Riwayat Kunjungan Saya')
@section('breadcrumb', 'Kunjungan / Riwayat Saya')

@section('content')
  <div class="panel" style="margin-bottom:22px">
    <form method="GET" action="{{ route('kunjungan.riwayat') }}" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end">
      <div>
        <label style="display:block;font-size:11.5px;font-weight:600;color:var(--brand-700);margin-bottom:4px">Hasil</label>
        <select name="hasil" style="font-size:12px;padding:8px 10px;border-radius:8px;border:1px solid var(--brand-100);color:var(--brand-700);background:var(--brand-50)">
          <option value="">Semua</option>
          @foreach ($hasilOptions as $hasil)
            <option value="{{ $hasil }}" {{ ($filters['hasil'] ?? '') === $hasil ? 'selected' : '' }}>{{ $hasil }}</option>
          @endforeach
        </select>
      </div>
      <div>
        <label style="display:block;font-size:11.5px;font-weight:600;color:var(--brand-700);margin-bottom:4px">Dari tanggal</label>
        <input type="date" name="dari" value="{{ $filters['dari'] ?? '' }}"
               style="font-size:12px;padding:7px 10px;border-radius:8px;border:1px solid var(--brand-100)">
      </div>
      <div>
        <label style="display:block;font-size:11.5px;font-weight:600;color:var(--brand-700);margin-bottom:4px">Sampai tanggal</label>
        <input type="date" name="sampai" value="{{ $filters['sampai'] ?? '' }}"
               style="font-size:12px;padding:7px 10px;border-radius:8px;border:1px solid var(--brand-100)">
      </div>
      <div style="flex:1;min-width:160px">
        <label style="display:block;font-size:11.5px;font-weight:600;color:var(--brand-700);margin-bottom:4px">Cari POI</label>
        <input type="text" name="poi" value="{{ $filters['poi'] ?? '' }}" placeholder="Nama POI..."
               style="width:100%;font-size:12px;padding:7px 10px;border-radius:8px;border:1px solid var(--brand-100)">
      </div>
      <button type="submit" class="btn-primary-custom" style="width:auto;padding:9px 18px">Terapkan</button>
      <a href="{{ route('kunjungan.riwayat') }}" style="font-size:12px;color:var(--brand-500);padding:9px 4px;text-decoration:none">Reset</a>
    </form>
  </div>

  <div class="table-panel">
    <div class="panel-head">
      <h3>Riwayat kunjungan saya</h3>
      <a href="{{ route('kunjungan.create') }}" class="btn-primary-custom" style="width:auto;padding:10px 16px">
        <i class="bi bi-plus-lg"></i> Catat kunjungan
      </a>
    </div>

    @if ($kunjungans->isEmpty())
      <div class="empty-state-rich">
        <i class="bi bi-journal-plus"></i>
        <p>Belum ada kunjungan yang tercatat.</p>
        <small>Mulai catat kunjungan pertama kamu lewat tombol di atas.</small>
      </div>
    @else
      <div style="overflow-x:auto">
        <table class="table-ledger">
          <thead>
            <tr>
              <th>Tanggal</th>
              <th>POI</th>
              <th>Produk</th>
              <th>Hasil</th>
              <th class="num" style="text-align:right">Nominal</th>
              <th>Catatan</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($kunjungans as $k)
              <tr>
                <td>{{ $k->tanggal_kunjungan->format('d/m/Y') }}</td>
                <td>{{ $k->poi->nama_poi ?? '-' }}</td>
                <td>{{ $k->produkList->pluck('produk')->implode(', ') ?: '-' }}</td>
                <td><span class="badge {{ $k->hasilBadgeClass() }}">{{ $k->hasil }}</span></td>
                <td class="num" style="text-align:right">{{ $k->nominal !== null ? 'Rp '.number_format((float) $k->nominal, 0, ',', '.') : '-' }}</td>
                <td>{{ $k->catatan ?? '-' }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      @include('partials.pagination', ['paginator' => $kunjungans])
    @endif
  </div>
@endsection
