<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>@yield('title', 'Dashboard') | PROMIS</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="{{ asset('assets/css/app.css') }}" rel="stylesheet">
@stack('head')
</head>
<body>
@php
    $user = auth()->user();
    // Only `sales` has a session-locked single active kantor (EnsureActiveKantor).
    // `admin_final` browses an aggregate "Semua Kantor Saya" view by default,
    // narrowed per-page via a validated ?kantor= filter (wired in Tahap 3+) —
    // confirmed against the real v1 dashboard.php, not session state.
    $activeKantorNama = 'Semua Kantor';
    if ($user && $user->isSales()) {
        $activeKantorId = session('active_kantor_id');
        $activeKantorNama = optional($user->kantor->firstWhere('id', $activeKantorId))->nama ?? 'Semua Kantor';
    } elseif ($user && $user->isAdminFinal()) {
        $activeKantorNama = 'Semua Kantor Saya';
    }

    $navGroups = [
        'Utama' => [
            ['label' => 'Dashboard', 'icon' => 'bi-grid-1x2', 'route' => 'dashboard', 'roles' => ['admin', 'admin_final', 'sales']],
            ['label' => 'Data POI', 'icon' => 'bi-geo-alt', 'route' => 'poi.index', 'roles' => ['admin', 'admin_final', 'sales']],
            ['label' => 'Catat Kunjungan', 'icon' => 'bi-journal-plus', 'route' => 'kunjungan.create', 'roles' => ['sales', 'admin_final', 'admin']],
            ['label' => 'Riwayat Kunjungan', 'icon' => 'bi-journal-check', 'route' => 'kunjungan.riwayat', 'roles' => ['sales']],
        ],
        'Laporan' => [
            ['label' => 'Dashboard Admin', 'icon' => 'bi-bar-chart-line', 'route' => 'histogram.index', 'roles' => ['admin', 'admin_final']],
            // Riwayat Kunjungan (kantor-wide) + Rekap Sales + Export Data are merged
            // into one entry sharing a tab bar (resources/views/laporan/_tabs.blade.php)
            // — lands on Riwayat Kunjungan since that's the tab with real content today.
            ['label' => 'Laporan', 'icon' => 'bi-journal-text', 'route' => 'kunjungan.index', 'roles' => ['admin', 'admin_final'], 'active_routes' => ['kunjungan.index', 'laporan.rekap-sales']],
        ],
        'Admin' => [
            ['label' => 'Kelola User', 'icon' => 'bi-person-gear', 'route' => 'user.index', 'roles' => ['admin']],
            ['label' => 'Pengaturan', 'icon' => 'bi-gear', 'route' => 'unit.index', 'roles' => ['admin']],
        ],
    ];
@endphp
<div class="app">
  <aside class="sidebar" id="sidebar">
    <div class="sb-brand"><div class="box">P</div><span>PROMIS</span></div>
    <nav class="sb-nav">
      @foreach ($navGroups as $group => $items)
        @php($visibleItems = array_filter($items, fn ($item) => in_array($user->role, $item['roles'], true)))
        @continue(empty($visibleItems))
        <div class="sb-group">{{ $group }}</div>
        @foreach ($visibleItems as $item)
          @php($isActive = request()->routeIs(...($item['active_routes'] ?? [$item['route'] ?? '__none__'])))
          <a class="sb-item @if($isActive) active @endif"
             href="{{ isset($item['route']) ? route($item['route']) : $item['href'] }}">
            <i class="bi {{ $item['icon'] }}"></i> {{ $item['label'] }}
          </a>
        @endforeach
      @endforeach
    </nav>
    <div class="sb-foot">&copy; {{ date('Y') }} PROMIS &middot; v2.0</div>
  </aside>

  <main class="main">
    <div class="topbar">
      <div style="display:flex;align-items:center;gap:10px">
        <button type="button" class="mobile-toggle" onclick="document.getElementById('sidebar').classList.toggle('open')">
          <i class="bi bi-list"></i>
        </button>
        <div>
          <h1>@yield('title', 'Dashboard')</h1>
          <div class="path">@yield('breadcrumb', 'Beranda')</div>
          <div class="kantor-chip kantor-chip-mobile"><i class="bi bi-building"></i> {{ $activeKantorNama }}</div>
        </div>
      </div>
      <div class="top-right">
        <div class="kantor-chip kantor-chip-desktop"><i class="bi bi-building"></i> {{ $activeKantorNama }}</div>
        <div class="user-chip" title="{{ $user->nama_lengkap }} &middot; {{ ucwords(str_replace('_', ' ', $user->role)) }}">
          <div class="av">{{ strtoupper(substr($user->nama_lengkap, 0, 2)) }}</div>
          <div class="meta">
            <b>{{ $user->nama_lengkap }}</b>
            <small>{{ ucwords(str_replace('_', ' ', $user->role)) }}</small>
          </div>
        </div>
        <form method="POST" action="{{ route('logout') }}" style="margin:0">
          @csrf
          <button type="submit" class="logout-btn" title="Logout" aria-label="Logout">
            <i class="bi bi-box-arrow-right"></i>
          </button>
        </form>
      </div>
    </div>

    <div class="content">
      @if (session('status'))
        <div class="form-status">{{ session('status') }}</div>
      @endif
      @yield('content')
    </div>
  </main>
</div>
<script src="{{ asset('assets/js/app.js') }}"></script>
@stack('scripts')
</body>
</html>
