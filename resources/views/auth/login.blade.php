<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login | PROMIS</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="{{ asset('assets/css/app.css') }}?v={{ filemtime(public_path('assets/css/app.css')) }}" rel="stylesheet">
</head>
<body>
<div class="split">
  <div class="left">
    <div class="left-photo-grid">
      <div style="background-image:url('{{ asset('assets/img/provinsi-jambi.jpg') }}')"><span>Jambi</span></div>
      <div style="background-image:url('{{ asset('assets/img/provinsi-kepri.jpg') }}')"><span>Kepulauan Riau</span></div>
      <div style="background-image:url('{{ asset('assets/img/provinsi-riau.jpg') }}')"><span>Riau</span></div>
      <div style="background-image:url('{{ asset('assets/img/provinsi-sumbar.jpg') }}')"><span>Sumatera Barat</span></div>
    </div>
    <div class="left-overlay"></div>

    <div class="brand-mark">
      <div class="logo-box">P</div>
      <span>PROMIS</span>
    </div>
    <div class="left-body">
      <h1>Sistem pemantauan POI &amp; kunjungan sales.</h1>
      <p>Dipakai tim W02 buat catat kunjungan dan kelola data mitra harian.</p>
    </div>
    <div class="left-foot">&copy; {{ date('Y') }} PROMIS &middot; BNI Internal System &mdash; Jambi, Riau, Kepulauan Riau, Sumatera Barat</div>
  </div>

  <div class="right">
    <div class="form-card">
      <span class="kicker">Masuk ke akun</span>
      <h2 style="margin-top:6px">Selamat datang kembali</h2>
      <p class="sub">Masuk ke akun kamu untuk lanjut kerja</p>

      @if ($errors->any())
        <div class="form-error">{{ $errors->first() }}</div>
      @endif

      <form method="POST" action="{{ route('login') }}">
        @csrf
        <div class="field">
          <label>NPP (Username)</label>
          <div class="input-wrap">
            <i class="bi bi-person leading"></i>
            <input type="text" name="npp" value="{{ old('npp') }}" placeholder="Masukkan NPP" required autofocus>
          </div>
        </div>
        <div class="field">
          <label>Password</label>
          <div class="input-wrap">
            <i class="bi bi-lock leading"></i>
            <input type="password" id="pw" name="password" placeholder="Masukkan password" required>
            <i class="bi bi-eye toggle-eye" id="toggleEye"></i>
          </div>
        </div>
        <button type="submit" class="btn-primary-custom">
          Masuk <i class="bi bi-arrow-right"></i>
        </button>
      </form>
    </div>
  </div>
</div>

<script>
document.getElementById('toggleEye').addEventListener('click', function(){
  const pw = document.getElementById('pw');
  const isPw = pw.type === 'password';
  pw.type = isPw ? 'text' : 'password';
  this.className = isPw ? 'bi bi-eye-slash toggle-eye' : 'bi bi-eye toggle-eye';
});
</script>
</body>
</html>
