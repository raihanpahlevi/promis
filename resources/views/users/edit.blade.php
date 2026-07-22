@extends('layouts.app')

@section('title', 'Edit User')
@section('breadcrumb', 'Manajemen User / Edit')

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
        <h3>Edit User &mdash; {{ $user->nama_lengkap }}</h3>
        @if ($user->is_active)
          <span class="badge badge-ok">Aktif</span>
        @else
          <span class="badge badge-no">Nonaktif</span>
        @endif
      </div>

      <form method="POST" action="{{ route('user.update', $user) }}">
        @csrf
        @method('PUT')
        @include('users._form', ['user' => $user, 'kantorOptions' => $kantorOptions, 'roleOptions' => $roleOptions])

        <div style="display:flex;gap:10px;margin-top:20px">
          <button type="submit" class="btn-primary-custom" style="width:auto;padding:12px 22px">
            <i class="bi bi-check2"></i> Simpan Perubahan
          </button>
          <a href="{{ route('user.index') }}" class="btn-primary-custom" style="width:auto;padding:12px 22px;text-decoration:none;background:transparent;color:var(--brand-700);border:1.5px solid var(--brand-100)">
            Batal
          </a>
        </div>
      </form>
    </div>

    <div style="display:flex;flex-direction:column;gap:16px">
      <div class="panel">
        <div class="panel-head"><h3>Reset Password</h3></div>
        <p style="font-size:13px;color:#8A6B55;line-height:1.6">
          Mengatur ulang password user ini kembali ke NPP-nya ({{ $user->npp }}) dan memaksa user ganti
          password saat login berikutnya. Gunakan ini kalau user lupa password / terkunci &mdash; tidak ada
          flow "lupa password" mandiri di sistem ini.
        </p>
        <form method="POST" action="{{ route('user.reset-password', $user) }}" onsubmit="return confirm('Reset password user ini ke NPP ({{ $user->npp }})?');">
          @csrf
          <button type="submit" class="btn-primary-custom" style="width:auto;padding:10px 18px">
            <i class="bi bi-key"></i> Reset Password ke NPP
          </button>
        </form>
      </div>

      <div class="panel">
        <div class="panel-head"><h3>{{ $user->is_active ? 'Nonaktifkan User' : 'Aktifkan Kembali User' }}</h3></div>
        @if ($user->id === auth()->id())
          <p style="font-size:13px;color:#8A6B55;line-height:1.6">Anda tidak dapat menonaktifkan akun Anda sendiri melalui halaman ini.</p>
        @else
          <p style="font-size:13px;color:#8A6B55;line-height:1.6">
            @if ($user->is_active)
              User yang dinonaktifkan tidak akan bisa login ke sistem lagi sampai diaktifkan kembali.
            @else
              User ini sedang nonaktif dan tidak bisa login. Aktifkan kembali kalau perlu.
            @endif
          </p>
          <form method="POST" action="{{ route('user.toggle-active', $user) }}" onsubmit="return confirm('{{ $user->is_active ? 'Nonaktifkan' : 'Aktifkan kembali' }} user ini?');">
            @csrf
            <button type="submit" class="btn-primary-custom" style="width:auto;padding:10px 18px;{{ $user->is_active ? 'background:var(--danger)' : '' }}">
              @if ($user->is_active)
                <i class="bi bi-x-octagon"></i> Nonaktifkan
              @else
                <i class="bi bi-arrow-counterclockwise"></i> Aktifkan Kembali
              @endif
            </button>
          </form>
        @endif
      </div>

      <div class="panel" style="border:1.5px solid var(--danger)">
        <div class="panel-head"><h3 style="color:var(--danger)">Hapus Permanen</h3></div>
        @if ($hardDeleteBlockedReason)
          <p style="font-size:13px;color:#8A6B55;line-height:1.6">{{ $hardDeleteBlockedReason }}</p>
        @else
          @php
            $confirmMsg = "Hapus PERMANEN user {$user->nama_lengkap}?";
            if ($kunjunganCount > 0) {
                $confirmMsg .= " Ini juga akan menghapus {$kunjunganCount} riwayat kunjungan miliknya.";
            }
            $confirmMsg .= ' Tindakan ini tidak bisa dibatalkan.';
          @endphp
          <p style="font-size:13px;color:#8A6B55;line-height:1.6">
            Tindakan ini <b>permanen dan tidak bisa dibatalkan</b>.
            @if ($kunjunganCount > 0)
              User ini punya <b>{{ $kunjunganCount }} riwayat kunjungan</b> yang akan ikut terhapus.
            @endif
            Kalau cuma mau mencabut akses login (dan bisa dibatalkan), gunakan Nonaktifkan di atas.
          </p>
          <form method="POST" action="{{ route('user.destroy', $user) }}" onsubmit="return confirm(@json($confirmMsg));">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn-primary-custom" style="width:auto;padding:10px 18px;background:var(--danger)">
              <i class="bi bi-trash3"></i> Hapus Permanen
            </button>
          </form>
        @endif
      </div>
    </div>
  </div>
@endsection
