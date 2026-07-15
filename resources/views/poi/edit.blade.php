@extends('layouts.app')

@section('title', 'Edit POI')
@section('breadcrumb', 'Data POI / Edit')

@section('content')
  <div class="grid-2">
    <div class="panel">
      @if ($errors->any())
        <div class="form-error">{{ $errors->first() }}</div>
      @endif
      @if (session('status'))
        <div class="form-status">{{ session('status') }}</div>
      @endif

      <div class="panel-head">
        <h3>Edit POI &mdash; {{ $poi->nama_poi }}</h3>
        @if ($poi->status === 'aktif')
          <span class="badge badge-ok">Aktif</span>
        @else
          <span class="badge badge-no">Nonaktif</span>
        @endif
      </div>

      <form method="POST" action="{{ route('poi.update', $poi) }}">
        @csrf
        @method('PUT')
        @include('poi._form', ['poi' => $poi])

        <div style="display:flex;gap:10px;margin-top:20px">
          <button type="submit" class="btn-primary-custom" style="width:auto;padding:12px 22px">
            <i class="bi bi-check2"></i> Simpan Perubahan
          </button>
          <a href="{{ route('poi.index') }}" class="btn-primary-custom" style="width:auto;padding:12px 22px;text-decoration:none;background:transparent;color:var(--brand-700);border:1.5px solid var(--brand-100)">
            Batal
          </a>
        </div>
      </form>
    </div>

    <div style="display:flex;flex-direction:column;gap:16px">
      <div class="panel">
        <div class="panel-head"><h3>{{ $poi->status === 'aktif' ? 'Nonaktifkan POI' : 'Aktifkan Kembali POI' }}</h3></div>

        @if ($poi->status === 'aktif')
          <form method="POST" action="{{ route('poi.destroy', $poi) }}" onsubmit="return confirm('Yakin ingin menonaktifkan POI ini?');">
            @csrf
            <div class="field">
              <label>Alasan (wajib diisi)</label>
              <textarea name="alasan" required rows="3" placeholder="Contoh: Duplikat data, POI tutup permanen, dll."
                style="width:100%;padding:12px 14px;border-radius:12px;border:1.5px solid var(--brand-100);font-size:13.5px;color:var(--brand-900);outline:none;resize:vertical">{{ old('alasan') }}</textarea>
            </div>
            <button type="submit" class="btn-primary-custom" style="width:auto;padding:10px 18px;background:var(--danger)">
              <i class="bi bi-x-octagon"></i> Nonaktifkan POI
            </button>
          </form>
        @else
          <form method="POST" action="{{ route('poi.reopen', $poi) }}" onsubmit="return confirm('Yakin ingin mengaktifkan kembali POI ini?');">
            @csrf
            <div class="field">
              <label>Alasan reopen (wajib diisi)</label>
              <textarea name="alasan" required rows="3" placeholder="Contoh: Data ternyata masih valid, POI buka kembali, dll."
                style="width:100%;padding:12px 14px;border-radius:12px;border:1.5px solid var(--brand-100);font-size:13.5px;color:var(--brand-900);outline:none;resize:vertical">{{ old('alasan') }}</textarea>
            </div>
            <button type="submit" class="btn-primary-custom" style="width:auto;padding:10px 18px">
              <i class="bi bi-arrow-counterclockwise"></i> Aktifkan Kembali
            </button>
          </form>
        @endif
      </div>

      <div class="table-panel">
        <div class="panel-head"><h3>Riwayat Hapus / Reopen</h3></div>
        @if ($poi->reopenLogs->isEmpty())
          <div class="empty-state-rich">
            <i class="bi bi-clock-history"></i>
            <p>Belum ada riwayat untuk POI ini.</p>
            <small>Aksi hapus atau reopen akan tercatat di sini.</small>
          </div>
        @else
          <table class="table-ledger">
            <thead>
              <tr>
                <th>Tanggal</th>
                <th>Aksi</th>
                <th>Alasan</th>
                <th>Oleh</th>
              </tr>
            </thead>
            <tbody>
              @foreach ($poi->reopenLogs as $log)
                <tr>
                  <td>{{ $log->created_at->format('d M Y H:i') }}</td>
                  <td>
                    @if ($log->action === 'hapus')
                      <span class="badge badge-no">Hapus</span>
                    @else
                      <span class="badge badge-ok">Reopen</span>
                    @endif
                  </td>
                  <td>{{ $log->alasan }}</td>
                  <td>{{ $log->user->nama_lengkap ?? '-' }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        @endif
      </div>
    </div>
  </div>
@endsection
