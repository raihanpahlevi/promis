@extends('layouts.app')

@section('title', 'Riwayat Kunjungan')
@section('breadcrumb', 'Kunjungan / Riwayat Kantor')

@section('content')
  @include('laporan._tabs')

  <div class="panel" style="margin-bottom:22px">
    <form method="GET" action="{{ route('kunjungan.index') }}" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end">
      @if ($kantorAreaOptions->isNotEmpty())
        <div>
          <label style="display:block;font-size:11.5px;font-weight:600;color:var(--brand-700);margin-bottom:4px">Area</label>
          <select name="area" style="font-size:12px;padding:8px 10px;border-radius:8px;border:1px solid var(--brand-100);color:var(--brand-700);background:var(--brand-50)">
            <option value="">Semua Area</option>
            @foreach ($kantorAreaOptions as $kantorArea)
              <option value="{{ $kantorArea }}" {{ $selectedKantorArea === $kantorArea ? 'selected' : '' }}>{{ $kantorArea }}</option>
            @endforeach
          </select>
        </div>
      @endif
      @if ($kantorClusterOptions->isNotEmpty())
        <div>
          <label style="display:block;font-size:11.5px;font-weight:600;color:var(--brand-700);margin-bottom:4px">Cabang-Cluster</label>
          <select name="cluster" style="font-size:12px;padding:8px 10px;border-radius:8px;border:1px solid var(--brand-100);color:var(--brand-700);background:var(--brand-50)">
            <option value="">Semua Cluster</option>
            @foreach ($kantorClusterOptions as $kantorCluster)
              <option value="{{ $kantorCluster }}" {{ $selectedKantorCluster === $kantorCluster ? 'selected' : '' }}>{{ $kantorCluster }}</option>
            @endforeach
          </select>
        </div>
      @endif
      <div>
        <label style="display:block;font-size:11.5px;font-weight:600;color:var(--brand-700);margin-bottom:4px">Cabang</label>
        <select name="kantor_id" style="font-size:12px;padding:8px 10px;border-radius:8px;border:1px solid var(--brand-100);color:var(--brand-700);background:var(--brand-50)">
          <option value="">Semua Cabang{{ auth()->user()->isAdminFinal() ? ' saya' : '' }}</option>
          @foreach ($kantorOptions as $kantor)
            <option value="{{ $kantor->id }}" {{ (string) ($filters['kantor_id'] ?? '') === (string) $kantor->id ? 'selected' : '' }}>{{ $kantor->nama }}</option>
          @endforeach
        </select>
      </div>
      <div>
        <label style="display:block;font-size:11.5px;font-weight:600;color:var(--brand-700);margin-bottom:4px">Sales</label>
        <select name="sales_id" style="font-size:12px;padding:8px 10px;border-radius:8px;border:1px solid var(--brand-100);color:var(--brand-700);background:var(--brand-50)">
          <option value="">Semua sales</option>
          @foreach ($salesOptions as $sales)
            <option value="{{ $sales->id }}" {{ (string) ($filters['sales_id'] ?? '') === (string) $sales->id ? 'selected' : '' }}>{{ $sales->nama_lengkap }}</option>
          @endforeach
        </select>
      </div>
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
      <a href="{{ route('kunjungan.index') }}" style="font-size:12px;color:var(--brand-500);padding:9px 4px;text-decoration:none">Reset</a>
    </form>
  </div>

  <div class="table-panel">
    <div class="panel-head">
      <h3>Riwayat kunjungan {{ auth()->user()->isAdminFinal() ? '- kantor saya' : '- semua kantor' }}</h3>
      <a href="{{ route('export.kunjungan.download', array_filter($filters)) }}"
         class="btn-primary-custom" style="text-decoration:none;padding:8px 16px;width:auto;font-size:12.5px">
        <i class="bi bi-file-earmark-excel"></i> Export Excel
      </a>
    </div>

    @if ($kunjungans->isEmpty())
      <div class="empty-state-rich">
        <i class="bi bi-journal-x"></i>
        <p>Tidak ada kunjungan yang cocok dengan filter ini.</p>
        <small>Coba ubah atau reset filter di atas.</small>
      </div>
    @else
      <div style="overflow-x:auto">
        <table class="table-ledger">
          <thead>
            <tr>
              <th>Tanggal</th>
              <th>Kantor</th>
              <th>POI</th>
              <th>PIC</th>
              <th>Sales</th>
              <th>Produk</th>
              <th>Hasil</th>
              <th>Norek/CIF</th>
              <th class="num" style="text-align:right">Nominal</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            @foreach ($kunjungans as $k)
              @php
                $canReopen = in_array($k->hasil, [\App\Models\Kunjungan::HASIL_CLOSING, \App\Models\Kunjungan::HASIL_COLLECTING_DOKUMEN], true)
                  && ($latestKunjunganIdByPoi[$k->poi_id] ?? null) === $k->id;
              @endphp
              <tr>
                <td>{{ $k->tanggal_kunjungan->format('d/m/Y') }}</td>
                <td>{{ $k->poi->kantor->nama ?? '-' }}</td>
                <td>{{ $k->poi->nama_poi ?? '-' }}</td>
                <td>{{ $k->poi->pic ?? '-' }}</td>
                <td>{{ $k->sales->nama_lengkap ?? '-' }}</td>
                <td>{{ $k->produkList->pluck('produk')->implode(', ') ?: '-' }}</td>
                <td><span class="badge {{ $k->hasilBadgeClass() }}">{{ $k->hasil }}</span></td>
                <td>{{ $k->norek_cif ?? '-' }}</td>
                <td class="num" style="text-align:right">{{ $k->nominal !== null ? 'Rp '.number_format((float) $k->nominal, 0, ',', '.') : '-' }}</td>
                <td>
                  @if ($canReopen)
                    <form method="POST" action="{{ route('kunjungan.reopen', $k) }}"
                          onsubmit="return confirm('Yakin reopen kunjungan ini? POI akan dikembalikan ke status semula dan baris ini akan dihapus dari riwayat.');">
                      @csrf
                      <button type="submit" style="border:none;background:none;color:var(--danger);cursor:pointer;font-size:12px;white-space:nowrap">
                        <i class="bi bi-arrow-counterclockwise"></i> Reopen
                      </button>
                    </form>
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      @include('partials.pagination', ['paginator' => $kunjungans])
    @endif
  </div>
@endsection
