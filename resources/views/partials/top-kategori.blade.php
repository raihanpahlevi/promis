{{--
  "Top Kategori" panel — rendered twice on the dashboard (BNI / Non BNI).

  Expects:
    $items    array from DashboardController::sektorBreakdown() — each entry has
              sektor, total, persen, and up to 3 `subs` (sub_sektor, total, persen).
    $variant  'bni' | 'non' — picks the green vs brown palette.
    $chartId  unique canvas id (two instances on one page).

  Presentation only: no query or shaping happens here, the controller already
  returns exactly this shape.

  Note on the two different percentages on screen: `persen` is the BNI *share
  within that category* (BNI 9,21% + Non BNI 90,79% = 100%), which is the
  business metric this dashboard has always shown — while the donut's slice
  sizes are by raw volume. Those disagree on purpose (a category can be huge
  yet barely penetrated), hence the caption under the donut.
--}}
@php
  $isBni = ($variant ?? 'bni') === 'bni';

  // Green ramp for BNI, brand-brown ramp for Non BNI — dark (biggest) to pale.
  $palette = $isBni
      ? ['#1B5E20', '#2E7D32', '#4C9A51', '#86C08A', '#CFE4D0']
      : ['#3E2723', '#4E342E', '#6F4E37', '#A47148', '#E7D6C8'];

  $icons = [
      'Food & Beverage' => 'bi-cup-hot',
      'Retail & Shopping' => 'bi-bag',
      'Education' => 'bi-mortarboard',
      'Business & Office' => 'bi-briefcase',
      'Health Care' => 'bi-heart-pulse',
      'Leisure & Recreation' => 'bi-controller',
      'Travel & Accomodation' => 'bi-airplane',
      'Warehouse & Logistic' => 'bi-box-seam',
      'Wholesaler' => 'bi-shop',
      'Financial Service' => 'bi-cash-coin',
      'Residential Areas' => 'bi-house-door',
      'Lainnya' => 'bi-three-dots',
  ];

  // Biggest by count *within this panel*, not $items[0]: the 5 kategori are
  // ranked by BNI count and that same order is reused for the Non BNI panel
  // (a v1 quirk kept on purpose), so its first entry often isn't its largest —
  // which would leave the centre label naming a different kategori than the
  // donut's biggest slice.
  $top = collect($items)->sortByDesc('total')->first();

  // Bars are scaled against the largest sub value in THIS panel, so the
  // longest bar is always full width and the rest stay comparable to it.
  $maxSub = 0;
  foreach ($items as $item) {
      foreach ($item['subs'] as $sub) {
          $maxSub = max($maxSub, (int) $sub['total']);
      }
  }
@endphp

<div class="panel topkat {{ $isBni ? 'topkat-bni' : 'topkat-non' }}">
  <div class="topkat-head">
    <span class="topkat-badge"><i class="bi bi-bank"></i></span>
    <h3>Top Kategori &ndash; {{ $isBni ? 'BNI' : 'Non BNI' }}</h3>
  </div>

  @if (empty($items))
    <div class="empty-state-rich">
      <i class="bi bi-pie-chart"></i>
      <p>Belum ada data kategori.</p>
      <small>Data muncul setelah POI terisi pada cakupan kantor ini.</small>
    </div>
  @else
    <div class="topkat-overview">
      <div class="topkat-donut">
        <canvas id="{{ $chartId }}"></canvas>
        <div class="topkat-donut-center">
          <b>{{ number_format($top['total']) }}</b>
          <small>{{ $top['sektor'] }}</small>
        </div>
      </div>

      <ul class="topkat-legend">
        @foreach ($items as $i => $item)
          <li>
            <span class="topkat-dot" style="background:{{ $palette[$i] ?? end($palette) }}"></span>
            <span class="topkat-legend-nm">{{ $item['sektor'] }}</span>
            <span class="topkat-legend-val">{{ number_format($item['total']) }} <em>({{ $item['persen'] }}%)</em></span>
          </li>
        @endforeach
      </ul>
    </div>

    <p class="topkat-note">
      Angka tengah = kategori teratas &middot; persen = porsi {{ $isBni ? 'BNI' : 'Non BNI' }} di dalam kategori itu
    </p>

    <div class="topkat-cards">
      @foreach ($items as $i => $item)
        <div class="topkat-card">
          <div class="topkat-cat">
            {{-- Solid panel colour, not the ramp: the pale 4th/5th steps left
                 a white glyph on a near-white circle. Ramp stays on the donut
                 and its legend dots, where the gradation carries meaning. --}}
            <span class="topkat-icon">
              <i class="bi {{ $icons[$item['sektor']] ?? 'bi-tag' }}"></i>
            </span>
            <div class="topkat-cat-txt">
              <b>{{ $item['sektor'] }}</b>
              <small>{{ number_format($item['total']) }} ({{ $item['persen'] }}%)</small>
            </div>
          </div>

          <div class="topkat-subs">
            @forelse ($item['subs'] as $sub)
              <div class="topkat-sub">
                <span class="topkat-sub-lbl" title="{{ $sub['sub_sektor'] }}">{{ $sub['sub_sektor'] }}</span>
                <span class="topkat-bar">
                  <i style="width:{{ $maxSub > 0 ? round($sub['total'] / $maxSub * 100, 1) : 0 }}%"></i>
                </span>
                <span class="topkat-sub-val">{{ number_format($sub['total']) }} <em>({{ $sub['persen'] }}%)</em></span>
              </div>
            @empty
              <div class="topkat-sub topkat-sub-empty">Belum ada sub kategori</div>
            @endforelse
          </div>
        </div>
      @endforeach
    </div>
  @endif
</div>

@if (! empty($items))
  @push('scripts')
  <script>
    new Chart(document.getElementById('{{ $chartId }}'), {
      type: 'doughnut',
      data: {
        labels: @json(array_column($items, 'sektor')),
        datasets: [{
          data: @json(array_map('intval', array_column($items, 'total'))),
          backgroundColor: @json(array_slice($palette, 0, count($items))),
          borderWidth: 0,
          hoverOffset: 6,
        }],
      },
      options: {
        cutout: '70%',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {display: false},
          tooltip: {
            callbacks: {
              label: function (ctx) {
                return ctx.label + ': ' + ctx.raw.toLocaleString('id-ID');
              },
            },
          },
        },
      },
    });
  </script>
  @endpush
@endif
