@extends('layouts.app')

@section('title', 'Dashboard')
@section('breadcrumb', 'Beranda / Ringkasan')

@push('head')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
@endpush

@section('content')
  @if (session('status'))
    <div class="form-status">{{ session('status') }}</div>
  @endif

  @if ($kantorOptions->isNotEmpty() || $areaOptions->isNotEmpty())
    <div class="panel" style="margin-bottom:16px;padding:14px 16px">
      {{-- Area: plain single-select, applies immediately on change (its own
           small form so submitting it doesn't carry over Cabang-Cluster/
           Cabang selections scoped to a different Area). --}}
      @if ($areaOptions->isNotEmpty())
        <form method="GET" action="{{ route('dashboard') }}" style="margin-bottom:14px">
          <input type="hidden" name="periode" value="{{ $periode }}">
          <label style="display:block;font-size:11.5px;font-weight:600;color:var(--brand-700);margin-bottom:6px">Area</label>
          <select name="area" onchange="this.form.submit()" style="max-width:280px">
            <option value="">Semua Area</option>
            @foreach ($areaOptions as $areaOpt)
              <option value="{{ $areaOpt }}" @selected($selectedArea === $areaOpt)>{{ $areaOpt }}</option>
            @endforeach
          </select>
        </form>
      @endif

      {{-- Cabang-Cluster and Cabang: multi-select chip pickers, both apply
           together via Terapkan (not on every click) — Cabang-Cluster's
           options are scoped to the Area above, Cabang's to whichever
           Cabang-Cluster was applied on the last Terapkan (see
           DashboardController::buildHierarchicalScope). --}}
      <form method="GET" action="{{ route('dashboard') }}" id="formKantorMonitor">
        <input type="hidden" name="periode" value="{{ $periode }}">
        <input type="hidden" name="area" value="{{ $selectedArea }}">
        <div style="display:flex;flex-direction:column;gap:14px">
          @if ($clusterOptions->isNotEmpty())
            <div>
              <label style="display:block;font-size:11.5px;font-weight:600;color:var(--brand-700);margin-bottom:6px">Cabang-Cluster</label>
              <div class="kantor-picker-chips" id="clusterChipList"></div>
              <div class="poi-wrap" style="margin-top:8px;max-width:380px">
                <i class="bi bi-search poi-icon-left"></i>
                <input type="text" id="clusterPickerInput" class="autocomplete-input" placeholder="Cari &amp; tambah Cabang-Cluster..." autocomplete="off">
                <div id="clusterPickerDropdown" class="autocomplete-dropdown"></div>
              </div>
            </div>
          @endif

          <div class="kantor-monitor-row" style="display:flex;flex-wrap:wrap;align-items:flex-start;gap:14px">
            @if ($kantorOptions->isNotEmpty())
              <div style="flex:1;min-width:260px">
                <label style="display:block;font-size:11.5px;font-weight:600;color:var(--brand-700);margin-bottom:6px">Cabang</label>
                <div class="kantor-picker-chips" id="kantorChipList"></div>
                <div class="poi-wrap" style="margin-top:8px;max-width:380px">
                  <i class="bi bi-search poi-icon-left"></i>
                  <input type="text" id="kantorPickerInput" class="autocomplete-input" placeholder="Cari &amp; tambah Cabang untuk dipantau..." autocomplete="off">
                  <div id="kantorPickerDropdown" class="autocomplete-dropdown"></div>
                </div>
              </div>
            @endif
            <div class="kantor-monitor-actions" style="display:flex;align-items:center;gap:10px;margin-left:auto;padding-top:2px">
              <button type="submit" class="btn-primary-custom" style="width:auto;padding:8px 18px;font-size:12.5px">Terapkan</button>
              @if ($selectedKantorIds !== [] || $selectedClusters !== [] || $selectedArea !== null)
                <a href="{{ route('dashboard', array_filter(['periode' => $periode])) }}" style="font-size:12px;color:var(--brand-500);text-decoration:none">Reset</a>
              @endif
            </div>
          </div>
        </div>
      </form>
    </div>
  @endif

  <div class="stat-grid" style="grid-template-columns:repeat(3,1fr)">
    <div class="stat-card hero">
      <span class="kicker">Total POI &mdash; {{ $kantorLabel }}</span>
      <div class="num" style="font-size:32px;margin-top:8px">{{ number_format($totals['total_poi']) }}</div>
      <div class="lbl" style="margin-top:2px">Seluruh titik POI tercatat di cakupan ini</div>
    </div>

    <div class="stat-card accent-ok">
      <div class="kicker-row">
        <div>
          <span class="kicker">BNI &mdash; Merchant + Non Merchant</span>
          <div class="num" style="margin-top:8px">{{ number_format($totals['total_bni']) }}</div>
        </div>
        <div class="ratio-ring" style="--pct:{{ $totals['persen_bni'] }};--ring-color:var(--ok)">
          <span>{{ $totals['persen_bni'] }}%</span>
        </div>
      </div>
      <div class="stat-trend" style="color:var(--ok);margin-top:10px">
        <i class="bi bi-arrow-up-right"></i> {{ number_format($closing['total_closing']) }} closing
        <span class="text-muted" style="font-weight:600">({{ $closing['persen_akuisisi_vs_non'] }}% vs Bank Lain)</span>
      </div>
    </div>

    <div class="stat-card accent-danger">
      <div class="kicker-row">
        <div>
          <span class="kicker">Non BNI</span>
          <div class="num" style="margin-top:8px">{{ number_format($totals['total_non']) }}</div>
        </div>
        <div class="ratio-ring" style="--pct:{{ $totals['persen_non'] }};--ring-color:var(--danger)">
          <span>{{ $totals['persen_non'] }}%</span>
        </div>
      </div>
      <div class="text-muted" style="font-size:11.5px;margin-top:10px">{{ $totals['persen_non'] }}% dari total POI</div>
    </div>
  </div>

  <div class="grid-3">
    <div class="panel">
      <div class="panel-head"><h3>Top Ring Area (Ring 1&ndash;4)</h3></div>
      @foreach ($area['all'] as $key => $a)
        @php($rcls = match($key) { 'Ring 1' => 'r1', 'Ring 2' => 'r2', 'Ring 3' => 'r3', default => 'r4' })
        <div class="area-label {{ $rcls }}">
          <span>{{ $a['label'] }}</span>
          <span class="muted">{{ number_format($a['total']) }} ({{ $a['persen'] }}%)</span>
        </div>
        <div class="area-bar-container">
          <div class="area-bar-fill pct-{{ $a['persen'] >= 60 ? 'vhigh' : ($a['persen'] >= 40 ? 'high' : ($a['persen'] >= 20 ? 'mid' : 'low')) }}" style="width:{{ $a['persen'] }}%"></div>
        </div>
      @endforeach
    </div>

    <div class="panel">
      <div class="panel-head"><h3>Top Ring Area &ndash; BNI</h3></div>
      @foreach ($area['bni'] as $key => $a)
        @php($rcls = match($key) { 'Ring 1' => 'r1', 'Ring 2' => 'r2', 'Ring 3' => 'r3', default => 'r4' })
        <div class="area-label {{ $rcls }}">
          <span>{{ $a['label'] }}</span>
          <span class="muted">{{ number_format($a['total']) }} ({{ $a['persen'] }}%)</span>
        </div>
        <div class="area-bar-container">
          <div class="area-bar-fill pct-{{ $a['persen'] >= 60 ? 'vhigh' : ($a['persen'] >= 40 ? 'high' : ($a['persen'] >= 20 ? 'mid' : 'low')) }}" style="width:{{ $a['persen'] }}%"></div>
        </div>
      @endforeach
    </div>

    <div class="panel">
      <div class="panel-head"><h3>Top Ring Area &ndash; Non BNI</h3></div>
      @foreach ($area['non'] as $key => $a)
        @php($rcls = match($key) { 'Ring 1' => 'r1', 'Ring 2' => 'r2', 'Ring 3' => 'r3', default => 'r4' })
        <div class="area-label {{ $rcls }}">
          <span>{{ $a['label'] }}</span>
          <span class="muted">{{ number_format($a['total']) }} ({{ $a['persen'] }}%)</span>
        </div>
        <div class="area-bar-container">
          <div class="area-bar-fill pct-{{ $a['persen'] >= 60 ? 'vhigh' : ($a['persen'] >= 40 ? 'high' : ($a['persen'] >= 20 ? 'mid' : 'low')) }}" style="width:{{ $a['persen'] }}%"></div>
        </div>
      @endforeach
    </div>
  </div>

  <div class="grid-3">
    <div class="panel">
      <div class="panel-head"><h3>Top Kategori &ndash; BNI</h3></div>
      @forelse ($sektor['bni'] as $s)
        <div class="sektor-head">
          <span>{{ $s['sektor'] }}</span>
          <span>{{ number_format($s['total']) }} ({{ $s['persen'] }}%)</span>
        </div>
        @forelse ($s['subs'] as $sub)
          @php($color = $sub['persen'] >= 40 ? 'dot-green' : ($sub['persen'] >= 15 ? 'dot-orange' : 'dot-red'))
          <div class="sub"><span class="sub-dot {{ $color }}"></span>{{ $sub['sub_sektor'] }} ({{ $sub['total'] }} | {{ $sub['persen'] }}%)</div>
        @empty
          <div class="sub"><span class="sub-dot dot-red"></span>Tidak ada sub sektor</div>
        @endforelse
      @empty
        <div class="empty-state">Belum ada data sektor.</div>
      @endforelse
    </div>

    <div class="panel">
      <div class="panel-head"><h3>Top Kategori &ndash; Non BNI</h3></div>
      @forelse ($sektor['non'] as $s)
        <div class="sektor-head">
          <span>{{ $s['sektor'] }}</span>
          <span>{{ number_format($s['total']) }} ({{ $s['persen'] }}%)</span>
        </div>
        @forelse ($s['subs'] as $sub)
          @php($color = $sub['persen'] >= 40 ? 'dot-green' : ($sub['persen'] >= 15 ? 'dot-orange' : 'dot-red'))
          <div class="sub"><span class="sub-dot {{ $color }}"></span>{{ $sub['sub_sektor'] }} ({{ $sub['total'] }} | {{ $sub['persen'] }}%)</div>
        @empty
          <div class="sub"><span class="sub-dot dot-red"></span>Tidak ada sub sektor</div>
        @endforelse
      @empty
        <div class="empty-state">Belum ada data sektor.</div>
      @endforelse
    </div>

    <div class="panel">
      <div class="panel-head" style="flex-wrap:wrap;gap:8px">
        <h3>Hasil Kunjungan Sales</h3>
        <div class="periode-tabs">
          @foreach (['day' => 'Day', 'week' => 'Week', 'month' => 'Month'] as $val => $lbl)
            <a href="{{ route('dashboard', array_filter(['kantor' => $selectedKantorIds, 'periode' => $val])) }}"
               class="{{ $periode === $val ? 'active' : '' }}">{{ $lbl }}</a>
          @endforeach
        </div>
      </div>

      <div style="overflow-x:auto">
        <table class="table-ledger" style="margin-bottom:12px">
          <thead><tr><th>Hasil Kunjungan</th><th class="num" style="text-align:right">Jumlah</th></tr></thead>
          <tbody>
            @foreach ($funnel as $status => $total)
              <tr><td>{{ $status }}</td><td class="num" style="text-align:right;font-weight:700">{{ number_format($total) }}</td></tr>
            @endforeach
            <tr style="background:var(--brand-50)">
              <td style="font-weight:800">TOTAL</td>
              <td class="num" style="text-align:right;font-weight:800">{{ number_format($totalHasilKunjungan) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <h4 style="margin:6px 0;font-size:12.5px;color:var(--brand-700)">Produk BNI &ndash; Closing</h4>
      <div class="produk-grid">
        @foreach ($produk as $nama => $total)
          <div class="produk-box {{ $total > 0 ? 'active' : '' }}">
            <div class="produk-kode">{{ $nama }}</div>
            <div class="produk-total">{{ $total }}</div>
          </div>
        @endforeach
      </div>

      <div class="chart-mini">
        <canvas id="chartKunjungan"></canvas>
      </div>
    </div>
  </div>
@endsection

@push('scripts')
<script>
// Shared multi-select chip picker (search + add + removable chips), reused
// for both the Cabang and Cabang-Cluster pickers (2026-07-23) — was a single
// kantor-only IIFE before; generalized so a second instance didn't have to
// duplicate the whole search/keyboard-nav/chip-render logic. `id` is always
// compared as a string so it works for both numeric kantor ids and cluster
// name strings.
function initChipPicker(cfg) {
  var chipList = document.getElementById(cfg.chipListId);
  var input = document.getElementById(cfg.inputId);
  if (!chipList || !input) return;

  var dropdown = document.getElementById(cfg.dropdownId);
  var data = cfg.data;
  var selectedIds = cfg.selectedIds.map(String);

  var selected = data.filter(function (k) { return selectedIds.indexOf(String(k.id)) !== -1; });
  var currentVisible = [];
  var highlighted = -1;

  function escHtml(str) {
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function availableOptions() {
    var selectedIdSet = selected.map(function (s) { return String(s.id); });
    return data.filter(function (k) { return selectedIdSet.indexOf(String(k.id)) === -1; });
  }

  function renderChips() {
    if (selected.length === 0) {
      chipList.innerHTML = '<span style="font-size:12px;color:#8A6B55">' + cfg.emptyText + '</span>';
      return;
    }
    chipList.innerHTML = selected.map(function (k) {
      return '<span class="kantor-picker-chip">' + escHtml(k.label)
        + '<input type="hidden" name="' + cfg.fieldName + '" value="' + escHtml(k.id) + '">'
        + '<button type="button" class="kantor-picker-chip-remove" data-id="' + escHtml(k.id) + '" aria-label="Hapus ' + escHtml(k.label) + '">&times;</button></span>';
    }).join('');
  }

  function renderDropdown(keyword) {
    var q = keyword.toLowerCase().trim();
    var pool = availableOptions();
    currentVisible = q === '' ? pool : pool.filter(function (k) {
      return k.label.toLowerCase().indexOf(q) !== -1;
    });

    if (currentVisible.length === 0) {
      dropdown.innerHTML = '<div class="poi-option no-result">'
        + (pool.length === 0 ? cfg.allSelectedText : cfg.noResultText) + '</div>';
    } else {
      dropdown.innerHTML = currentVisible.map(function (k) {
        return '<div class="poi-option" data-id="' + escHtml(k.id) + '">' + escHtml(k.label) + '</div>';
      }).join('');
    }
    highlighted = -1;
  }

  function addItem(id) {
    var item = data.find(function (k) { return String(k.id) === String(id); });
    if (!item || selected.some(function (s) { return String(s.id) === String(id); })) return;
    selected.push(item);
    renderChips();
    input.value = '';
    renderDropdown('');
  }

  function removeItem(id) {
    selected = selected.filter(function (s) { return String(s.id) !== String(id); });
    renderChips();
    renderDropdown(input.value);
  }

  function highlightItem(idx) {
    var opts = dropdown.querySelectorAll('.poi-option[data-id]');
    opts.forEach(function (o) { o.classList.remove('highlighted'); });
    if (idx >= 0 && idx < opts.length) {
      opts[idx].classList.add('highlighted');
      opts[idx].scrollIntoView({block: 'nearest'});
    }
  }

  input.addEventListener('focus', function () {
    renderDropdown(input.value);
    dropdown.classList.add('open');
  });
  input.addEventListener('blur', function () {
    setTimeout(function () { dropdown.classList.remove('open'); }, 150);
  });
  input.addEventListener('input', function () {
    renderDropdown(input.value);
    dropdown.classList.add('open');
  });
  input.addEventListener('keydown', function (e) {
    if (e.key === 'ArrowDown') {
      var opts = dropdown.querySelectorAll('.poi-option[data-id]');
      if (!opts.length) return;
      e.preventDefault();
      highlighted = Math.min(highlighted + 1, opts.length - 1);
      highlightItem(highlighted);
    } else if (e.key === 'ArrowUp') {
      var opts2 = dropdown.querySelectorAll('.poi-option[data-id]');
      if (!opts2.length) return;
      e.preventDefault();
      highlighted = Math.max(highlighted - 1, 0);
      highlightItem(highlighted);
    } else if (e.key === 'Enter') {
      e.preventDefault();
      if (highlighted >= 0 && highlighted < currentVisible.length) {
        addItem(currentVisible[highlighted].id);
      } else if (currentVisible.length === 1) {
        addItem(currentVisible[0].id);
      }
    } else if (e.key === 'Escape') {
      dropdown.classList.remove('open');
    } else if (e.key === 'Backspace' && input.value === '' && selected.length) {
      removeItem(selected[selected.length - 1].id);
    }
  });
  dropdown.addEventListener('mousedown', function (e) {
    var opt = e.target.closest('.poi-option[data-id]');
    if (opt) addItem(opt.dataset.id);
  });
  chipList.addEventListener('click', function (e) {
    var btn = e.target.closest('.kantor-picker-chip-remove');
    if (btn) removeItem(btn.dataset.id);
  });

  renderChips();
}

initChipPicker({
  chipListId: 'kantorChipList', inputId: 'kantorPickerInput', dropdownId: 'kantorPickerDropdown',
  data: @json($kantorOptions->map(fn ($k) => ['id' => $k->id, 'label' => $k->nama])),
  selectedIds: @json($selectedKantorIds),
  fieldName: 'kantor[]',
  emptyText: 'Belum ada Cabang dipilih &mdash; menampilkan semua.',
  noResultText: 'Cabang tidak ditemukan',
  allSelectedText: 'Semua Cabang sudah dipilih',
});

initChipPicker({
  chipListId: 'clusterChipList', inputId: 'clusterPickerInput', dropdownId: 'clusterPickerDropdown',
  data: @json($clusterOptions->map(fn ($c) => ['id' => $c, 'label' => $c])),
  selectedIds: @json($selectedClusters),
  fieldName: 'cluster[]',
  emptyText: 'Belum ada Cabang-Cluster dipilih &mdash; menampilkan semua di Area ini.',
  noResultText: 'Cabang-Cluster tidak ditemukan',
  allSelectedText: 'Semua Cabang-Cluster sudah dipilih',
});

new Chart(document.getElementById('chartKunjungan'), {
  type: 'bar',
  data: {
    labels: @json($chart['labels']),
    datasets: [
      {label: 'Closing', data: @json($chart['closing']), backgroundColor: '#6F4E37'},
      {label: 'Belum Closing', data: @json($chart['non_closing']), backgroundColor: '#D7C4B3'}
    ]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {legend: {position: 'bottom', labels: {font: {size: 11}}}},
    scales: {
      y: {beginAtZero: true, ticks: {font: {size: 10}}},
      x: {ticks: {font: {size: 9}}}
    }
  }
});
</script>
@endpush
