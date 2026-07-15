<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pilih Kantor | PROMIS</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="{{ asset('assets/css/app.css') }}" rel="stylesheet">
</head>
<body>
<div class="right" style="min-height:100vh">
  <div class="form-card">
    <span class="kicker">Sesi kerja</span>
    <h2 style="margin-top:6px">Pilih kantor aktif</h2>
    <p class="sub">Akun Anda ditugaskan ke lebih dari satu kantor &mdash; pilih salah satu untuk dipakai sesi ini.</p>

    @if ($errors->any())
      <div class="form-error">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('pilih-kantor') }}">
      @csrf
      <div class="field">
        <label>Kantor</label>
        <div class="input-wrap">
          <i class="bi bi-building leading"></i>
          <select name="kantor_id" required
                  style="width:100%;padding:12px 14px 12px 40px;border-radius:12px;border:1.5px solid var(--brand-100);font-size:16px;background:#fff;color:var(--brand-900);outline:none;appearance:none">
            <option value="" disabled selected>Pilih kantor&hellip;</option>
            @foreach ($kantorOptions as $kantor)
              <option value="{{ $kantor->id }}">{{ $kantor->nama }}</option>
            @endforeach
          </select>
        </div>
      </div>
      <button type="submit" class="btn-primary-custom">
        Lanjut ke dashboard <i class="bi bi-arrow-right"></i>
      </button>
    </form>
  </div>
</div>
</body>
</html>
