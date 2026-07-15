@php
  // Export Data isn't its own tab anymore — merged into Riwayat Kunjungan as
  // an "Export Excel" button that carries through whatever filters are
  // currently active on that table (see kunjungan/index.blade.php).
  $laporanTabs = [
    ['label' => 'Riwayat Kunjungan', 'route' => 'kunjungan.index'],
    ['label' => 'Rekap Sales', 'route' => 'laporan.rekap-sales'],
  ];
@endphp
<div class="section-tabs">
  @foreach ($laporanTabs as $tab)
    <a href="{{ route($tab['route']) }}" class="{{ request()->routeIs($tab['route']) ? 'active' : '' }}">{{ $tab['label'] }}</a>
  @endforeach
</div>
