@extends('layouts.app')

@section('title', 'Kelola Cabang')
@section('breadcrumb', 'Pengaturan / Kelola Cabang')

@section('content')
  @if (session('status'))
    <div class="form-status">{{ session('status') }}</div>
  @endif
  @if ($errors->any())
    <div class="form-error">{{ $errors->first() }}</div>
  @endif

  <p style="font-size:12.5px;color:#8A6B55;margin-top:-8px;margin-bottom:16px">
    Area &amp; Cabang-Cluster otomatis kesimpen di sini setiap kali import Data POI yang kolom
    Cabang-nya cocok (nggak perlu import terpisah). Halaman ini buat fix cepat satu-dua Cabang, atau
    bulk-edit tanpa upload ulang file POI-nya. <b>Yang perlu diinget:</b> mengetik nama Cabang yang
    beda di file import POI bikin Cabang <b>baru</b>, bukan mengganti yang lama &mdash; kalau cuma
    mau rename Cabang yang sudah ada, edit lewat sini (atau Export dari sini, edit, Import balik).
  </p>

  <div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap">
    <a href="{{ route('export.kantor.download') }}" class="btn-primary-custom" style="text-decoration:none;background:transparent;color:var(--brand-700);border:1.5px solid var(--brand-100);padding:9px 16px;width:auto">
      <i class="bi bi-download"></i> Export Excel
    </a>
    <a href="{{ route('kantor.import.create') }}" class="btn-primary-custom" style="text-decoration:none;padding:9px 16px;width:auto">
      <i class="bi bi-upload"></i> Import Excel
    </a>
  </div>

  <div class="panel" style="max-width:760px;margin-bottom:16px">
    <div class="panel-head"><h3>Tambah Cabang</h3></div>
    <form method="POST" action="{{ route('kantor.store') }}" style="display:flex;gap:10px;flex-wrap:wrap">
      @csrf
      <input type="text" name="kode" placeholder="Kode (contoh: JKT01)" required
             style="flex:1;min-width:120px;padding:10px 12px;border-radius:10px;border:1.5px solid var(--brand-100)">
      <input type="text" name="nama" placeholder="Nama Cabang" required
             style="flex:2;min-width:180px;padding:10px 12px;border-radius:10px;border:1.5px solid var(--brand-100)">
      <input type="text" name="area" placeholder="Area (opsional)"
             style="flex:1;min-width:140px;padding:10px 12px;border-radius:10px;border:1.5px solid var(--brand-100)">
      <input type="text" name="cabang_cluster" placeholder="Cabang-Cluster (opsional)"
             style="flex:1;min-width:160px;padding:10px 12px;border-radius:10px;border:1.5px solid var(--brand-100)">
      <button type="submit" class="btn-primary-custom" style="width:auto;padding:10px 20px">Tambah</button>
    </form>
  </div>

  <div class="table-panel">
    <div class="panel-head"><h3>Daftar Cabang</h3></div>
    @if ($kantorList->isEmpty())
      <div class="empty-state-rich">
        <i class="bi bi-building"></i>
        <p>Belum ada Cabang.</p>
        <small>Tambahkan Cabang pertama lewat form di atas, atau Import Excel.</small>
      </div>
    @else
      <div style="overflow-x:auto">
        <table class="table-ledger table-responsive-stack">
          <thead>
            <tr>
              <th style="width:100px">Kode</th>
              <th>Cabang</th>
              <th style="width:130px">Area</th>
              <th style="width:150px">Cabang-Cluster</th>
              <th></th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            @foreach ($kantorList as $kantor)
              <tr>
                <td colspan="5" data-label="Kode, Cabang, Area &amp; Cluster">
                  <form method="POST" action="{{ route('kantor.update', $kantor) }}" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    @csrf
                    @method('PUT')
                    <input type="text" name="kode" value="{{ $kantor->kode }}"
                           style="width:100px;padding:6px 10px;border-radius:8px;border:1px solid var(--brand-100);font-size:13px">
                    <input type="text" name="nama" value="{{ $kantor->nama }}"
                           style="flex:1;min-width:150px;padding:6px 10px;border-radius:8px;border:1px solid var(--brand-100);font-size:13px">
                    <input type="text" name="area" value="{{ $kantor->area }}" placeholder="Area"
                           style="width:130px;padding:6px 10px;border-radius:8px;border:1px solid var(--brand-100);font-size:13px">
                    <input type="text" name="cabang_cluster" value="{{ $kantor->cabang_cluster }}" placeholder="Cabang-Cluster"
                           style="width:150px;padding:6px 10px;border-radius:8px;border:1px solid var(--brand-100);font-size:13px">
                    <button type="submit" style="border:none;background:none;color:var(--brand-500);cursor:pointer;font-size:12px;white-space:nowrap">
                      <i class="bi bi-check2"></i> Simpan
                    </button>
                  </form>
                </td>
                <td data-label="Status">
                  @if ($kantor->is_active)
                    <span class="badge badge-ok">Aktif</span>
                  @else
                    <span class="badge badge-no">Nonaktif</span>
                  @endif
                </td>
                <td>
                  <form method="POST" action="{{ route('kantor.toggle-active', $kantor) }}">
                    @csrf
                    <button type="submit" style="border:none;background:none;color:var(--brand-500);cursor:pointer;font-size:12px;text-decoration:underline">
                      {{ $kantor->is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                    </button>
                  </form>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </div>
@endsection
