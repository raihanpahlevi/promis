@extends('layouts.app')

@section('title', 'Rekap Sales')
@section('breadcrumb', 'Laporan / Rekap Sales')

@push('head')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
<style>
  /* Two histogram panels side-by-side on desktop, stacked on mobile — the
     existing .grid-2 utility collapses at 1100px with an uneven 1.4fr/1fr
     split (built for a table+chart pairing elsewhere), neither of which fits
     two equal-width chart panels, so this is a small dedicated variant. */
  .histogram-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px}
  @media (max-width:900px){
    .histogram-grid{grid-template-columns:1fr}
  }
</style>
@endpush

@section('content')
  @include('laporan._tabs')

  {{--
    Two visually separate rows on purpose (2026-07-23 layout fix): mixing
    short single-line filters (date/select) and tall multi-part chip-picker
    blocks in ONE flex-wrap row broke — flexbox packs short items into
    whatever horizontal gap is next to a tall neighbor rather than flowing
    below it, so Terapkan ended up rendered next to a picker's label instead
    of after the whole block. Row 1 = short filters only. Row 2 = the chip
    pickers + Terapkan, mirroring the Dashboard kantor-monitor panel's own
    kantor-monitor-row/kantor-monitor-actions split (Terapkan pinned to the
    row's end via margin-left:auto, works regardless of how tall the pickers
    next to it get).
  --}}
  <div class="panel" style="margin-bottom:16px;padding:14px 16px">
    <form method="GET" action="{{ route('laporan.rekap-sales') }}">
      <input type="hidden" name="mode" value="{{ $mode }}">
      <div class="filters" style="flex-wrap:wrap;margin-bottom:14px">
        <input type="date" name="dari" value="{{ $dari }}" style="padding:9px 10px;border-radius:8px;border:1px solid var(--brand-100);font-size:16px">
        <input type="date" name="sampai" value="{{ $sampai }}" style="padding:9px 10px;border-radius:8px;border:1px solid var(--brand-100);font-size:16px">
        <select name="unit">
          <option value="">Semua Unit</option>
          @foreach ($unitOptions as $unit)
            <option value="{{ $unit->id }}" @selected($unitId === $unit->id)>{{ $unit->nama }}</option>
          @endforeach
        </select>
        @if ($kantorAreaOptions->isNotEmpty())
          <select name="area">
            <option value="">Semua Area</option>
            @foreach ($kantorAreaOptions as $kantorArea)
              <option value="{{ $kantorArea }}" @selected($selectedKantorArea === $kantorArea)>{{ $kantorArea }}</option>
            @endforeach
          </select>
        @endif
      </div>

      <div class="kantor-monitor-row" style="display:flex;flex-wrap:wrap;align-items:flex-start;gap:14px">
        @if ($kantorClusterOptions->isNotEmpty())
          <div style="flex:1;min-width:220px">
            <small style="color:#8A6B55;font-size:11.5px;display:block;margin-bottom:6px">Cabang-Cluster</small>
            <div class="kantor-picker-chips" id="clusterChipList"></div>
            <div class="poi-wrap" style="margin-top:8px;max-width:340px">
              <i class="bi bi-search poi-icon-left"></i>
              <input type="text" id="clusterPickerInput" class="autocomplete-input" placeholder="Cari &amp; tambah Cluster..." autocomplete="off">
              <div id="clusterPickerDropdown" class="autocomplete-dropdown"></div>
            </div>
          </div>
        @endif
        @if ($kantorOptions->isNotEmpty())
          <div style="flex:1;min-width:260px">
            <small style="color:#8A6B55;font-size:11.5px;display:block;margin-bottom:6px">
              Cabang <span style="font-weight:400">(kosongkan = {{ auth()->user()->isAdmin() ? 'semua kantor' : 'semua kantor saya' }})</span>
            </small>
            <div class="kantor-picker-chips" id="kantorChipList"></div>
            <div class="poi-wrap" style="margin-top:8px;max-width:340px">
              <i class="bi bi-search poi-icon-left"></i>
              <input type="text" id="kantorPickerInput" class="autocomplete-input" placeholder="Cari &amp; tambah Cabang..." autocomplete="off">
              <div id="kantorPickerDropdown" class="autocomplete-dropdown"></div>
            </div>
          </div>
        @endif
        <div class="kantor-monitor-actions" style="display:flex;align-items:center;gap:10px;margin-left:auto;padding-top:2px">
          <button type="submit" class="btn-primary-custom" style="width:auto;padding:8px 18px;font-size:12.5px">Terapkan</button>
        </div>
      </div>
    </form>
  </div>

  @php
    $carryQuery = [
        'dari' => $dari, 'sampai' => $sampai, 'unit' => $unitId,
        'area' => $selectedKantorArea, 'cluster' => $selectedKantorClusters, 'kantor' => $selectedKantorIds,
    ];
  @endphp
  <div class="section-tabs">
    <a href="{{ route('laporan.rekap-sales', array_filter($carryQuery + ['mode' => 'kunjungan'])) }}" class="{{ $mode === 'kunjungan' ? 'active' : '' }}">Kunjungan</a>
    <a href="{{ route('laporan.rekap-sales', array_filter($carryQuery + ['mode' => 'tidak'])) }}" class="{{ $mode === 'tidak' ? 'active' : '' }}">Tidak Kunjungan</a>
  </div>

  @php $summary = $histogram['summary']; @endphp
  <div class="stat-grid" style="grid-template-columns:repeat(3,1fr)">
    <div class="stat-card hero">
      <span class="kicker">Total Kunjungan</span>
      <div class="num" style="font-size:32px;margin-top:8px">{{ number_format($summary['total_kunjungan']) }}</div>
      <div class="lbl" style="margin-top:2px">Seluruh kunjungan pada rentang &amp; filter kantor ini</div>
    </div>

    <div class="stat-card accent-danger">
      <span class="kicker">Belum Kunjungan</span>
      <div class="num" style="margin-top:8px">{{ number_format($summary['total_tidak']) }}</div>
      <div class="lbl" style="margin-top:2px">Sales tanpa kunjungan pada rentang ini</div>
    </div>

    <div class="stat-card">
      <span class="kicker">Kantor Aktif</span>
      <div class="num" style="margin-top:8px">{{ $summary['kantor_aktif'] }} / {{ $summary['total_kantor'] }}</div>
      <div class="lbl" style="margin-top:2px">Kantor dengan minimal 1 kunjungan pada filter ini</div>
    </div>
  </div>

  <div class="histogram-grid">
    <div class="panel" style="margin-bottom:0">
      <div class="panel-head">
        <h3>Histogram Visit POI</h3>
        @if ($mode === 'kunjungan')
          <span class="badge" style="background:var(--brand-50);color:var(--brand-700)">{{ number_format($summary['total_kunjungan']) }} kunjungan</span>
        @else
          <span class="badge badge-no">{{ number_format($summary['total_tidak']) }} belum kunjungan</span>
        @endif
      </div>
      <div class="chart-lg"><canvas id="histUser"></canvas></div>
    </div>

    <div class="panel" style="margin-bottom:0">
      <div class="panel-head">
        <h3>Histogram Per Kantor{{ $unitId ? ' ('.$unitOptions->firstWhere('id', $unitId)?->nama.')' : '' }}</h3>
        <span class="badge" style="background:var(--brand-50);color:var(--brand-700)">{{ $summary['total_kantor'] }} kantor</span>
      </div>
      <div class="chart-lg"><canvas id="histKantor"></canvas></div>
    </div>
  </div>

  <div class="panel" style="margin-bottom:16px">
    @if ($mode === 'kunjungan')
      <div class="panel-head"><h3>Ringkasan Kunjungan per Kantor &amp; Unit</h3></div>
      @if ($kunjunganSummary->isEmpty())
        <div class="empty-state-rich">
          <i class="bi bi-clipboard-x"></i>
          <p>Tidak ada kunjungan pada rentang &amp; filter ini.</p>
          <small>Coba lebarkan rentang tanggal atau ganti filter unit/kantor.</small>
        </div>
      @else
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:14px">
          @foreach ($kunjunganSummary as $row)
            <div style="border:1.5px solid var(--brand-100);border-radius:12px;padding:14px 16px">
              <div class="panel-head" style="margin-bottom:10px">
                <h3>{{ $row['kantor']->nama }}</h3>
                <span class="badge" style="background:var(--ok-bg);color:var(--ok)">{{ $row['total'] }} visit</span>
              </div>
              <div style="display:flex;flex-direction:column;gap:6px">
                @foreach ($row['units'] as $unitRow)
                  <div style="display:flex;justify-content:space-between;gap:10px;font-size:13px">
                    <span>{{ $unitRow['nama'] }}</span>
                    <span><strong>{{ $unitRow['visit'] }}</strong> visit <span style="color:#8A6B55">({{ $unitRow['closing'] }} closing)</span></span>
                  </div>
                @endforeach
              </div>
            </div>
          @endforeach
        </div>
      @endif
    @else
      <div class="panel-head"><h3>Ringkasan Tidak Kunjungan per Kantor &amp; Unit</h3></div>
      @if ($tidakSummary->isEmpty())
        <div class="empty-state-rich">
          <i class="bi bi-emoji-smile"></i>
        </div>
      @else
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:14px">
          @foreach ($tidakSummary as $row)
            <div style="border:1.5px solid var(--brand-100);border-radius:12px;padding:14px 16px">
              <div class="panel-head" style="margin-bottom:10px">
                <h3>{{ $row['kantor']->nama }}</h3>
                <span class="badge badge-no">{{ $row['total'] }} orang</span>
              </div>
              <div style="display:flex;flex-direction:column;gap:6px">
                @foreach ($row['units'] as $unitRow)
                  <div style="display:flex;justify-content:space-between;gap:10px;font-size:13px">
                    <span>{{ $unitRow['nama'] }}</span>
                    <strong>{{ $unitRow['jumlah'] }}</strong>
                  </div>
                @endforeach
              </div>
            </div>
          @endforeach
        </div>
      @endif
    @endif
  </div>

  <div class="table-panel">
    @if ($mode === 'kunjungan')
      <div class="panel-head"><h3>Rekap Kunjungan per Sales</h3></div>
      @if ($kunjunganRows->isEmpty())
        <div class="empty-state-rich">
          <i class="bi bi-clipboard-x"></i>
          <p>Tidak ada sales dengan kunjungan pada rentang &amp; filter ini.</p>
          <small>Coba lebarkan rentang tanggal atau ganti filter unit/kantor.</small>
        </div>
      @else
        <div style="overflow-x:auto">
          <table class="table-ledger table-responsive-stack">
            <thead>
              <tr><th>Nama Sales</th><th>Unit</th><th>Kantor</th><th class="num" style="text-align:right">Total Visit</th><th class="num" style="text-align:right">Total Closing</th></tr>
            </thead>
            <tbody>
              @foreach ($kunjunganRows as $u)
                <tr>
                  <td class="cell-heading">{{ $u->nama_lengkap }}</td>
                  <td data-label="Unit">{{ $u->unit->nama ?? '-' }}</td>
                  <td data-label="Kantor">{{ $u->kantor->pluck('nama')->join(', ') ?: '-' }}</td>
                  <td data-label="Total Visit" class="num" style="text-align:right"><strong>{{ $u->total_visit }}</strong></td>
                  <td data-label="Total Closing" class="num" style="text-align:right">{{ $u->total_closing }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif
    @else
      <div class="panel-head"><h3>Sales Belum Kunjungan</h3></div>
      @if ($tidakRows->isEmpty())
        <div class="empty-state-rich">
          <i class="bi bi-emoji-smile"></i>
        </div>
      @else
        <div style="overflow-x:auto">
          <table class="table-ledger table-responsive-stack">
            <thead>
              <tr><th>Nama Sales</th><th>Unit</th><th>Kantor</th><th>Status</th></tr>
            </thead>
            <tbody>
              @foreach ($tidakRows as $u)
                <tr>
                  <td class="cell-heading">{{ $u->nama_lengkap }}</td>
                  <td data-label="Unit">{{ $u->unit->nama ?? '-' }}</td>
                  <td data-label="Kantor">{{ $u->kantor->pluck('nama')->join(', ') ?: '-' }}</td>
                  <td data-label="Status"><span class="badge badge-no">Tidak Kunjungan</span></td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      @endif
    @endif
  </div>

  @push('scripts')
  <script>
    // Shared multi-select chip picker (search + add + removable chips) —
    // same component as the dashboard's kantor monitor picker
    // (resources/views/dashboard.blade.php), generalized (2026-07-23) so a
    // second instance (Cabang-Cluster, string ids) doesn't have to duplicate
    // the whole search/keyboard-nav/chip-render logic. `id` is always
    // compared as a string so it works for both numeric kantor ids and
    // cluster name strings.
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
      data: @json($kantorClusterOptions->map(fn ($c) => ['id' => $c, 'label' => $c])),
      selectedIds: @json($selectedKantorClusters),
      fieldName: 'cluster[]',
      emptyText: 'Belum ada Cabang-Cluster dipilih &mdash; menampilkan semua.',
      noResultText: 'Cabang-Cluster tidak ditemukan',
      allSelectedText: 'Semua Cabang-Cluster sudah dipilih',
    });

    Chart.register(ChartDataLabels);

    // Both charts are mode-aware (2026-07-22): the "Kunjungan" tab must only
    // ever plot kunjungan/closing bars, the "Tidak Kunjungan" tab only the
    // tidak-kunjungan bar — no dataset from the other tab's data leaks in,
    // same rule already applied to the stat cards and the per-kantor/unit
    // breakdown panel above.
    new Chart(document.getElementById('histUser'), {
      type: 'bar',
      data: {
        labels: @json($histogram['labels']),
        datasets: @if ($mode === 'kunjungan')
          [
            {label: 'Kunjungan', data: @json($histogram['kunjungan']), backgroundColor: '#2E7D32', borderRadius: 6, borderSkipped: false},
            {label: 'Closing', data: @json($histogram['closing']), backgroundColor: '#1565C0', borderRadius: 6, borderSkipped: false}
          ]
        @else
          [
            {label: 'Tidak Kunjungan', data: @json($histogram['tidak2']), backgroundColor: '#C62828', borderRadius: 6, borderSkipped: false}
          ]
        @endif
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
          legend: {position: 'bottom'},
          tooltip: {callbacks: {label: (ctx) => ctx.dataset.label + ': ' + ctx.raw}},
          datalabels: {anchor: 'end', align: 'top', color: '#3E2723', font: {weight: 'bold', size: 11}, formatter: v => v > 0 ? v : ''}
        },
        scales: {y: {beginAtZero: true, ticks: {precision: 0}}}
      }
    });

    new Chart(document.getElementById('histKantor'), {
      type: 'bar',
      data: {
        labels: @json($histogram['labels2']),
        datasets: @if ($mode === 'kunjungan')
          [
            {label: 'Kunjungan', data: @json($histogram['kunjungan2']), backgroundColor: '#2E7D32', borderRadius: 6, borderSkipped: false},
            {label: 'Closing', data: @json($histogram['closing2']), backgroundColor: '#1565C0', borderRadius: 6, borderSkipped: false}
          ]
        @else
          [
            {label: 'Tidak Kunjungan', data: @json($histogram['tidak2']), backgroundColor: '#C62828', borderRadius: 6, borderSkipped: false}
          ]
        @endif
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
          legend: {position: 'bottom'},
          datalabels: {anchor: 'end', align: 'top', color: '#3E2723', font: {weight: 'bold', size: 11}, formatter: v => v > 0 ? v : ''}
        },
        scales: {y: {beginAtZero: true, ticks: {precision: 0}}}
      }
    });
  </script>
  @endpush
@endsection
