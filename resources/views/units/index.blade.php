@extends('layouts.app')

@section('title', 'Pengaturan')
@section('breadcrumb', 'Pengaturan / Kelola Unit')

@section('content')
  @if (session('status'))
    <div class="form-status">{{ session('status') }}</div>
  @endif
  @if ($errors->any())
    <div class="form-error">{{ $errors->first() }}</div>
  @endif

  <p style="font-size:12.5px;color:#8A6B55;margin-top:-8px;margin-bottom:16px">
    Unit yang <b>aktif</b> di sini dipakai sebagai pilihan di form user dan sebagai cakupan
    laporan Rekap Sales (Kunjungan / Tidak Kunjungan per unit). Nonaktifkan unit yang tidak
    perlu dipantau tanpa perlu menghapusnya.
  </p>

  <div class="panel" style="max-width:520px;margin-bottom:16px">
    <div class="panel-head"><h3>Tambah Unit</h3></div>
    <form method="POST" action="{{ route('unit.store') }}" style="display:flex;gap:10px">
      @csrf
      <input type="text" name="nama" placeholder="Contoh: BRANCH MANAGER" required
             style="flex:1;padding:10px 12px;border-radius:10px;border:1.5px solid var(--brand-100)">
      <button type="submit" class="btn-primary-custom" style="width:auto;padding:10px 20px">Tambah</button>
    </form>
  </div>

  <div class="table-panel">
    <div class="panel-head"><h3>Daftar Unit</h3></div>
    @if ($units->isEmpty())
      <div class="empty-state-rich">
        <i class="bi bi-diagram-3"></i>
        <p>Belum ada unit.</p>
        <small>Tambahkan unit pertama lewat form di atas.</small>
      </div>
    @else
      <table class="table-ledger">
        <thead>
          <tr><th>Nama Unit</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
          @foreach ($units as $unit)
            <tr>
              <td>
                <form method="POST" action="{{ route('unit.update', $unit) }}" style="display:flex;gap:8px;align-items:center">
                  @csrf
                  @method('PUT')
                  <input type="text" name="nama" value="{{ $unit->nama }}"
                         style="padding:6px 10px;border-radius:8px;border:1px solid var(--brand-100);font-size:13px">
                  <button type="submit" style="border:none;background:none;color:var(--brand-500);cursor:pointer;font-size:12px">
                    <i class="bi bi-check2"></i> Simpan
                  </button>
                </form>
              </td>
              <td>
                @if ($unit->is_active)
                  <span class="badge badge-ok">Aktif</span>
                @else
                  <span class="badge badge-no">Nonaktif</span>
                @endif
              </td>
              <td>
                <form method="POST" action="{{ route('unit.toggle-active', $unit) }}">
                  @csrf
                  <button type="submit" style="border:none;background:none;color:var(--brand-500);cursor:pointer;font-size:12px;text-decoration:underline">
                    {{ $unit->is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                  </button>
                </form>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>
@endsection
