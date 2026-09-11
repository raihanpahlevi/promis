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
            {{-- align-self:flex-end — the button sits level with the Cabang
                 search box at the bottom of the row, not up beside its label. --}}
            <div class="kantor-monitor-actions" style="display:flex;align-items:center;gap:10px;margin-left:auto;align-self:flex-end">
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

  {{-- .stat-grid-3, not an inline grid-template-columns: inline styles outrank
       the @media rules in app.css, so the inline version stayed 3-up on a
       phone and pushed the page into horizontal scroll. --}}
  <div class="stat-grid stat-grid-3">
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
      <div class="panel-head"><h3>Top Ring Area (Ring 1&ndash;3)</h3></div>
      {{-- A ring covers a different distance in a big city than elsewhere, so
           the band can only be named when everything in scope is one class. --}}
      <p style="font-size:11px;color:#8A6B55;margin:-8px 0 12px">
        @if ($ringJarak === null)
          Cakupan ini gabungan Kota Besar &amp; Non Kota Besar &mdash; saring per Cabang untuk melihat jaraknya.
        @else
          Jarak sesuai Cabang pada cakupan ini.
        @endif
      </p>
      @foreach ($area['all'] as $key => $a)
        @php($rcls = match($key) { 'Ring 1' => 'r1', 'Ring 2' => 'r2', 'Ring 3' => 'r3', default => 'r4' })
        <div class="area-label {{ $rcls }}">
          <span>{{ $a['label'] }}{{ isset($ringJarak[$key]) ? ' ('.$ringJarak[$key].')' : '' }}</span>
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
          <span>{{ $a['label'] }}{{ isset($ringJarak[$key]) ? ' ('.$ringJarak[$key].')' : '' }}</span>
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
          <span>{{ $a['label'] }}{{ isset($ringJarak[$key]) ? ' ('.$ringJarak[$key].')' : '' }}</span>
          <span class="muted">{{ number_format($a['total']) }} ({{ $a['persen'] }}%)</span>
        </div>
        <div class="area-bar-container">
          <div class="area-bar-fill pct-{{ $a['persen'] >= 60 ? 'vhigh' : ($a['persen'] >= 40 ? 'high' : ($a['persen'] >= 20 ? 'mid' : 'low')) }}" style="width:{{ $a['persen'] }}%"></div>
        </div>
      @endforeach
    </div>
  </div>

  {{-- Two equal full-width columns (2026-07-29 redesign): these panels used to
       be squeezed into a 3-up row alongside Hasil Kunjungan Sales, which left
       no room for the donut + per-kategori sub-bars. --}}
  <div class="topkat-grid">
    @include('partials.top-kategori', [
      'items' => $sektor['bni'], 'variant' => 'bni', 'chartId' => 'topkatBni',
      'panelTotal' => $sektor['total_bni'], 'panelShare' => $sektor['persen_bni'], 'grandTotal' => $sektor['grand_total'],
    ])
    @include('partials.top-kategori', [
      'items' => $sektor['non'], 'variant' => 'non', 'chartId' => 'topkatNon',
      'panelTotal' => $sektor['total_non'], 'panelShare' => $sektor['persen_non'], 'grandTotal' => $sektor['grand_total'],
    ])
  </div>

  <div class="grid-1">
    <div class="panel">
      <div class="panel-head" style="flex-wrap:wrap;gap:8px">
        <h3>Hasil Kunjungan Sales</h3>
        <div class="periode-tabs">
          @foreach (['day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'all' => 'All'] as $val => $lbl)
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

      {{-- Kontribusi Cabang — menggantikan grafik batang per Cabang.
           Grafik batang tidak sanggup memuat 112 Cabang (pedoman: batang
           efektif sampai ~15 kategori), dan yang lebih penting: grafik lama
           dibangun dari tabel kunjungan, jadi Cabang tanpa kunjungan tidak
           punya batang sama sekali dan hilang dari layar. Padahal justru itu
           yang paling perlu terlihat. --}}
      <div class="cakupan">
        <div class="cakupan-head">
          <div>
            <div class="cakupan-angka">
              {{ $cakupan['sudah'] }} <span>/ {{ $cakupan['jumlah'] }} Cabang</span>
            </div>
            <div class="cakupan-sub">sudah berkontribusi pada periode ini &mdash; {{ $cakupan['persen'] }}%</div>
          </div>

          @if ($cakupan['jumlah'] > 0)
            <div class="cakupan-filter" role="group" aria-label="Saring Cabang">
              <button type="button" class="is-active" data-saring="semua" aria-pressed="true">Semua</button>
              <button type="button" data-saring="sudah" aria-pressed="false">Sudah</button>
              <button type="button" data-saring="belum" aria-pressed="false">Belum</button>
            </div>
          @endif
        </div>

        @if ($cakupan['jumlah'] === 0)
          <div class="empty-state-rich">
            <i class="bi bi-diagram-3" aria-hidden="true"></i>
            <p>Belum ada Cabang pada filter ini.</p>
          </div>
        @else
          <div class="cakupan-bar" aria-hidden="true">
            <div class="cakupan-bar-fill" style="width:{{ $cakupan['persen'] }}%"></div>
          </div>

          {{-- Ringkasan hasil pada cakupan ini. Closing vs belum closing dulu,
               baru rinciannya per tahap follow-up. --}}
          @if ($cakupan['total'] > 0)
            <div class="cakupan-hasil">
              <div class="cakupan-hasil-utama">
                <div class="ch-box ch-closing">
                  <span class="ch-angka">{{ number_format($cakupan['closing']) }}</span>
                  <span class="ch-label">Closing</span>
                </div>
                <div class="ch-box">
                  <span class="ch-angka">{{ number_format($cakupan['belum_closing']) }}</span>
                  <span class="ch-label">Belum Closing</span>
                </div>
              </div>
              <div class="cakupan-tahap">
                @foreach ($cakupan['tahap'] as $nama => $n)
                  @continue($nama === \App\Models\Kunjungan::HASIL_CLOSING)
                  <span class="cakupan-tahap-item {{ $n === 0 ? 'is-nol' : '' }}">
                    {{ $nama }} <strong>{{ number_format($n) }}</strong>
                  </span>
                @endforeach
              </div>
            </div>
          @endif

          {{-- Produk yang tercatat pada cakupan ini. Di tingkat panel, bukan di
               dalam kartu per Cabang: pertanyaannya "produk apa saja yang
               closing", dan itu soal keseluruhan. --}}
          @if ($produkCakupan['total_closing'] > 0 || $produkCakupan['total_non_closing'] > 0)
            <div class="cakupan-produk">
              <h5>Produk Tercatat</h5>
              <div class="cakupan-produk-kolom">
                <div class="cakupan-produk-kotak ok">
                  <div class="cakupan-produk-judul">
                    Produk Closing <span>{{ number_format($produkCakupan['total_closing']) }}</span>
                  </div>
                  @if ($produkCakupan['closing'] === [])
                    <div class="cakupan-produk-kosong">Belum ada produk pada kunjungan closing.</div>
                  @else
                    <div class="cakupan-produk-list">
                      @foreach ($produkCakupan['closing'] as $nama => $n)
                        <span>{{ $nama }} <strong>{{ number_format($n) }}</strong></span>
                      @endforeach
                    </div>
                  @endif
                </div>

                <div class="cakupan-produk-kotak">
                  <div class="cakupan-produk-judul">
                    Produk Belum Closing <span>{{ number_format($produkCakupan['total_non_closing']) }}</span>
                  </div>
                  @if ($produkCakupan['non_closing'] === [])
                    <div class="cakupan-produk-kosong">Belum ada produk pada kunjungan yang belum closing.</div>
                  @else
                    <div class="cakupan-produk-list">
                      @foreach ($produkCakupan['non_closing'] as $nama => $n)
                        <span>{{ $nama }} <strong>{{ number_format($n) }}</strong></span>
                      @endforeach
                    </div>
                  @endif
                </div>
              </div>
            </div>
          @endif

          <div class="cakupan-kosong" id="cakupanKosong" hidden></div>

          <div class="cakupan-areas">
            @foreach ($cakupan['areas'] as $area)
              <section class="cakupan-area" data-area>
                <h4>
                  {{ $area['nama'] }}
                  <span>{{ $area['sudah'] }}/{{ $area['jumlah'] }}</span>
                </h4>
                <div class="cakupan-grid">
                  @foreach ($area['cabang'] as $c)
                    {{-- Tombol, bukan div: rincian tahap harus bisa dibuka lewat
                         keyboard juga, tidak cuma hover. Cabang tanpa kunjungan
                         tidak bisa diklik karena memang tidak ada yang dibuka. --}}
                    <button type="button"
                            class="cakupan-sel lv-{{ $c['level'] }}"
                            data-sudah="{{ $c['total'] > 0 ? '1' : '0' }}"
                            @disabled($c['total'] === 0)
                            @if ($c['total'] > 0) aria-expanded="false" @endif
                            title="{{ $c['nama'] }}">
                      <span class="cakupan-sel-nama">{{ $c['nama'] }}</span>
                      <span class="cakupan-sel-angka">
                        {{ number_format($c['total']) }}
                        @if ($c['closing'] > 0)
                          <em>{{ number_format($c['closing']) }} closing</em>
                        @endif
                      </span>
                    </button>
                    @if ($c['total'] > 0)
                      <div class="cakupan-detail" hidden>
                        <h5>{{ $c['nama'] }}</h5>
                        <div class="cakupan-detail-ringkas">
                          <span><strong>{{ number_format($c['total']) }}</strong> kunjungan</span>
                          <span class="ok"><strong>{{ number_format($c['closing']) }}</strong> closing</span>
                          <span class="belum"><strong>{{ number_format($c['belum_closing']) }}</strong> belum closing</span>
                        </div>
                        <dl class="cakupan-detail-tahap">
                          @foreach ($c['tahap'] as $nama => $n)
                            <div class="{{ $n === 0 ? 'is-nol' : '' }}">
                              <dt>{{ $nama }}</dt>
                              <dd>{{ number_format($n) }}</dd>
                            </div>
                          @endforeach
                        </dl>
                      </div>
                    @endif
                  @endforeach
                </div>
              </section>
            @endforeach
          </div>
        @endif
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

(function () {
  // Saring petak Cabang. Menyembunyikan juga judul Area yang jadi kosong,
  // supaya tidak ada kepala Area menggantung tanpa isi.
  var tombol = document.querySelectorAll('.cakupan-filter button');
  if (!tombol.length) return;

  var kosong = document.getElementById('cakupanKosong');

  function tutupSemuaRincian() {
    document.querySelectorAll('.cakupan-detail').forEach(function (d) { d.hidden = true; });
    document.querySelectorAll('.cakupan-sel[aria-expanded]').forEach(function (b) {
      b.setAttribute('aria-expanded', 'false');
      b.classList.remove('is-open');
    });
  }

  tombol.forEach(function (btn) {
    btn.addEventListener('click', function () {
      var pilih = btn.dataset.saring;

      tombol.forEach(function (b) {
        var aktif = b === btn;
        b.classList.toggle('is-active', aktif);
        b.setAttribute('aria-pressed', aktif ? 'true' : 'false');
      });

      // Rincian yang terbuka ikut ditutup: kalau tidak, panelnya bisa
      // tertinggal terbuka sementara petak pemiliknya sudah disembunyikan.
      tutupSemuaRincian();

      var total = 0;
      document.querySelectorAll('.cakupan-area').forEach(function (area) {
        var tampil = 0;
        area.querySelectorAll('.cakupan-sel').forEach(function (sel) {
          var cocok = pilih === 'semua'
            || (pilih === 'sudah' && sel.dataset.sudah === '1')
            || (pilih === 'belum' && sel.dataset.sudah === '0');
          sel.hidden = !cocok;
          if (cocok) tampil++;
        });
        area.hidden = tampil === 0;
        total += tampil;
      });

      kosong.textContent = pilih === 'belum'
        ? 'Semua Cabang pada cakupan ini sudah berkontribusi.'
        : 'Belum ada Cabang yang berkontribusi pada cakupan ini.';
      kosong.hidden = total > 0;
    });
  });

  // Buka rincian tahap satu per satu — dua panel terbuka sekaligus bikin
  // petaknya loncat-loncat dan susah dibandingkan.
  document.querySelectorAll('.cakupan-sel[aria-expanded]').forEach(function (sel) {
    sel.addEventListener('click', function () {
      var detail = sel.nextElementSibling;
      if (!detail || !detail.classList.contains('cakupan-detail')) return;
      var buka = detail.hidden;

      tutupSemuaRincian();

      detail.hidden = !buka;
      sel.setAttribute('aria-expanded', buka ? 'true' : 'false');
      sel.classList.toggle('is-open', buka);
    });
  });
})();
</script>
@endpush
