<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ganti Password | PROMIS</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="{{ asset('assets/css/app.css') }}?v={{ filemtime(public_path('assets/css/app.css')) }}" rel="stylesheet">
</head>
<body>
<div class="right" style="min-height:100vh">
  <div class="form-card">
    <span class="kicker">Keamanan akun</span>
    <h2 style="margin-top:6px">Ganti password</h2>
    <p class="sub">
      @if (auth()->user()->force_password_change)
        Ini login pertama Anda &mdash; password awal (NPP) wajib diganti sebelum lanjut.
      @else
        Perbarui password akun Anda.
      @endif
    </p>

    @if ($errors->any())
      <div class="form-error">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('ganti-password') }}">
      @csrf
      <div class="field">
        <label>Password lama</label>
        <div class="input-wrap">
          <i class="bi bi-lock leading"></i>
          <input type="password" name="password_lama" placeholder="Password saat ini" required autofocus>
        </div>
      </div>
      <div class="field">
        <label>Password baru</label>
        <div class="input-wrap">
          <i class="bi bi-key leading"></i>
          <input type="password" name="password_baru" placeholder="Minimal 8 karakter" required minlength="8">
        </div>
      </div>
      <div class="field">
        <label>Konfirmasi password baru</label>
        <div class="input-wrap">
          <i class="bi bi-key leading"></i>
          <input type="password" name="password_baru_confirmation" placeholder="Ulangi password baru" required minlength="8">
        </div>
      </div>
      <button type="submit" class="btn-primary-custom">
        Simpan password baru <i class="bi bi-check2"></i>
      </button>
    </form>

    @unless (auth()->user()->force_password_change)
      <a href="{{ route('dashboard') }}" class="btn-primary-custom" style="margin-top:14px;background:transparent;color:var(--brand-700);border:1.5px solid var(--brand-100);text-decoration:none">
        Batal, kembali ke dashboard
      </a>
    @endunless
  </div>
</div>
</body>
</html>
