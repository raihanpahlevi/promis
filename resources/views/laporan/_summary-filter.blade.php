{{--
  Shared period + Area/Cluster/Cabang filter for the two Summary reports.
  Expects the keys LaporanController::summaryFilterViewData() supplies, plus
  $dari/$sampai and $formAction.

  Same two-row shape as Rekap Sales: short inputs first, then the chip pickers
  with Terapkan. Mixing them in one wrapping flex row puts the button beside a
  picker's label instead of after the whole block.
--}}
<div class="panel">
  <div class="panel-head"><h3>Filter</h3></div>

  <form method="GET" action="{{ $formAction }}">
    <div class="filters" style="flex-wrap:wrap">
      <div class="filter-field">
        <small class="text-muted">Dari Tanggal</small>
        <input type="date" name="dari" value="{{ $dari }}">
      </div>
      <div class="filter-field">
        <small class="text-muted">Sampai Tanggal</small>
        <input type="date" name="sampai" value="{{ $sampai }}">
      </div>
      @if ($kantorAreaOptions->isNotEmpty())
        <div class="filter-field">
          <small class="text-muted">Area</small>
          <select name="area">
            <option value="">Semua Area</option>
            @foreach ($kantorAreaOptions as $kantorArea)
              <option value="{{ $kantorArea }}" @selected($selectedKantorArea === $kantorArea)>{{ $kantorArea }}</option>
            @endforeach
          </select>
        </div>
      @endif
    </div>

    <div class="filter-divider"></div>

    <div class="kantor-monitor-row">
      @if ($kantorClusterOptions->isNotEmpty())
        <div class="filter-field" style="flex:1;min-width:220px">
          <small class="text-muted">Cabang-Cluster</small>
          <div class="kantor-picker-chips" id="clusterChipList"></div>
          <div class="poi-wrap" style="max-width:340px">
            <i class="bi bi-search poi-icon-left"></i>
            <input type="text" id="clusterPickerInput" class="autocomplete-input" placeholder="Cari &amp; tambah Cluster..." autocomplete="off">
            <div id="clusterPickerDropdown" class="autocomplete-dropdown"></div>
          </div>
        </div>
      @endif
      @if ($kantorOptions->isNotEmpty())
        <div class="filter-field" style="flex:1;min-width:260px">
          <small class="text-muted">
            Cabang <span style="font-weight:400">(kosongkan = semua)</span>
          </small>
          <div class="kantor-picker-chips" id="kantorChipList"></div>
          <div class="poi-wrap" style="max-width:340px">
            <i class="bi bi-search poi-icon-left"></i>
            <input type="text" id="kantorPickerInput" class="autocomplete-input" placeholder="Cari &amp; tambah Cabang..." autocomplete="off">
            <div id="kantorPickerDropdown" class="autocomplete-dropdown"></div>
          </div>
        </div>
      @endif
      <div class="kantor-monitor-actions">
        <button type="submit" class="btn-primary-custom" style="width:auto;padding:8px 18px;font-size:12.5px">Terapkan</button>
      </div>
    </div>
  </form>
</div>

@push('scripts')
<script>
  // Same chip-picker component as Rekap Sales / Dashboard.
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
      var ids = selected.map(function (s) { return String(s.id); });
      return data.filter(function (k) { return ids.indexOf(String(k.id)) === -1; });
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
      currentVisible = q === '' ? pool : pool.filter(function (k) { return k.label.toLowerCase().indexOf(q) !== -1; });
      if (currentVisible.length === 0) {
        dropdown.innerHTML = '<div class="poi-option no-result">' + (pool.length === 0 ? cfg.allSelectedText : cfg.noResultText) + '</div>';
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

    input.addEventListener('focus', function () { renderDropdown(input.value); dropdown.classList.add('open'); });
    input.addEventListener('blur', function () { setTimeout(function () { dropdown.classList.remove('open'); }, 150); });
    input.addEventListener('input', function () { renderDropdown(input.value); dropdown.classList.add('open'); });
    input.addEventListener('keydown', function (e) {
      var opts = dropdown.querySelectorAll('.poi-option[data-id]');
      if (e.key === 'ArrowDown') {
        if (!opts.length) return;
        e.preventDefault();
        highlighted = Math.min(highlighted + 1, opts.length - 1);
        highlightItem(highlighted);
      } else if (e.key === 'ArrowUp') {
        if (!opts.length) return;
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
</script>
@endpush
