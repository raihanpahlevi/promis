@extends('layouts.app')

@section('title', 'Catat Kunjungan')
@section('breadcrumb', 'Kunjungan / Catat Kunjungan')

@section('content')
  @if ($errors->any())
    <div class="form-error">
      <ul style="margin:0;padding-left:18px">
        @foreach ($errors->all() as $error)
          <li>{{ $error }}</li>
        @endforeach
      </ul>
    </div>
  @endif

  @if ($kantorOptions->isNotEmpty())
    <form method="GET" action="{{ route('kunjungan.create') }}" style="margin-bottom:16px">
      <div class="filters">
        <select name="kantor" onchange="this.form.submit()">
          <option value="" disabled {{ $kantorId ? '' : 'selected' }}>Pilih kantor&hellip;</option>
          @foreach ($kantorOptions as $kantor)
            <option value="{{ $kantor->id }}" @selected($kantorId === $kantor->id)>{{ $kantor->nama }}</option>
          @endforeach
        </select>
      </div>
    </form>
  @endif

  @if ($kantorId === null)
    <div class="panel">
      <div class="empty-state">Pilih kantor terlebih dahulu untuk mulai mencatat kunjungan.</div>
    </div>
  @else
    <div class="panel" style="max-width:760px">
      <div class="panel-head"><h3>Catat kunjungan baru</h3></div>

      <form method="GET" action="{{ route('kunjungan.create') }}" style="margin-bottom:18px">
        @if (session('active_kantor_id') === null)
          <input type="hidden" name="kantor" value="{{ $kantorId }}">
        @endif
        <div class="filters" style="flex-wrap:wrap">
          <select name="sektor" onchange="this.form.submit()">
            <option value="">Semua Sektor</option>
            @foreach ($sektorOptions as $s)
              <option value="{{ $s }}" @selected(($filters['sektor'] ?? '') === $s)>{{ $s }}</option>
            @endforeach
          </select>
          <select name="sub_sektor" onchange="this.form.submit()">
            <option value="">Semua Sub Sektor</option>
            @foreach ($subSektorOptions as $s)
              <option value="{{ $s }}" @selected(($filters['sub_sektor'] ?? '') === $s)>{{ $s }}</option>
            @endforeach
          </select>
          <select name="area" onchange="this.form.submit()">
            <option value="">Semua Area</option>
            @foreach ($areaOptions as $a)
              <option value="{{ $a }}" @selected(($filters['area'] ?? '') === $a)>{{ $a }}</option>
            @endforeach
          </select>
        </div>
      </form>

      <form method="POST" action="{{ route('kunjungan.store') }}" id="formKunjungan">
        @csrf
        @if (session('active_kantor_id') === null)
          <input type="hidden" name="kantor_id" value="{{ $kantorId }}">
        @endif

        <div class="field" style="margin-bottom:16px">
          <label style="display:block;font-size:12.5px;font-weight:600;color:var(--brand-700);margin-bottom:6px">
            POI &mdash; hanya yang belum bermitra BNI
          </label>
          @if ($poiOptions->isEmpty())
            <div class="form-error">Tidak ada POI yang bisa dikunjungi untuk filter ini (kemungkinan semua sudah bermitra BNI atau nonaktif).</div>
          @else
            <div class="poi-wrap">
              <i class="bi bi-search poi-icon-left"></i>
              <input type="text" id="poiInput" placeholder="Ketik nama POI untuk mencari..." autocomplete="off">
              <input type="hidden" name="poi_id" id="poiHidden" value="{{ old('poi_id') }}">
              <div id="poiDropdown"></div>
            </div>

            <div id="poiLockedNotice" style="display:none;margin-top:12px">
              <div class="form-error" id="poiLockedMessage"></div>
              <button type="button" id="poiLockedBack" class="btn-primary-custom"
                      style="width:auto;padding:9px 18px;background:transparent;color:var(--brand-700);border:1.5px solid var(--brand-100)">
                <i class="bi bi-arrow-left"></i> Kembali
              </button>
            </div>
          @endif
        </div>

        <div id="kunjunganFormFields">
          <div class="field" style="margin-bottom:16px">
            <label style="display:block;font-size:12.5px;font-weight:600;color:var(--brand-700);margin-bottom:6px">Produk BNI yang ditawarkan</label>
            <div class="produk-checkbox-grid">
              @foreach ($produkOptions as $produk)
                <label class="produk-checkbox">
                  <input type="checkbox" name="produk[]" value="{{ $produk }}"
                         {{ collect(old('produk', []))->contains($produk) ? 'checked' : '' }}>
                  {{ $produk }}
                </label>
              @endforeach
            </div>
          </div>

          <div class="field" style="margin-bottom:16px">
            <label style="display:block;font-size:12.5px;font-weight:600;color:var(--brand-700);margin-bottom:6px">Hasil kunjungan</label>
            <select name="hasil" id="hasilSelect" required
                    style="width:100%;padding:12px 14px;border-radius:12px;border:1.5px solid var(--brand-100);font-size:16px;background:#fff;color:var(--brand-900)">
              <option value="" disabled {{ old('hasil') ? '' : 'selected' }}>Pilih hasil&hellip;</option>
              @foreach ($hasilOptions as $hasil)
                <option value="{{ $hasil }}" {{ old('hasil') === $hasil ? 'selected' : '' }}>{{ $hasil }}</option>
              @endforeach
            </select>
          </div>

          <div class="field" style="margin-bottom:16px">
            <label style="display:block;font-size:12.5px;font-weight:600;color:var(--brand-700);margin-bottom:6px">Nomor Rekening/CIF (opsional)</label>
            <input type="text" name="norek_cif" value="{{ old('norek_cif') }}" placeholder="Nomor rekening atau CIF..." autocomplete="off"
                   style="width:100%;padding:12px 14px;border-radius:12px;border:1.5px solid var(--brand-100);font-size:16px;color:var(--brand-900)">
          </div>

          <div class="field" id="statusMitraField" style="margin-bottom:16px;display:none">
            <label style="display:block;font-size:12.5px;font-weight:600;color:var(--brand-700);margin-bottom:6px">Status mitra baru</label>
            <select name="status_mitra_baru" id="statusMitraSelect"
                    style="width:100%;padding:12px 14px;border-radius:12px;border:1.5px solid var(--brand-100);font-size:16px;background:#fff;color:var(--brand-900)">
              <option value="" disabled {{ old('status_mitra_baru') ? '' : 'selected' }}>Pilih status mitra&hellip;</option>
              @foreach ($statusMitraAfterClosingOptions as $status)
                <option value="{{ $status }}" {{ old('status_mitra_baru') === $status ? 'selected' : '' }}>{{ $status }}</option>
              @endforeach
            </select>
            <small style="color:#8A6B55;font-size:11.5px">Wajib diisi karena hasil kunjungan Closing.</small>
          </div>

          <div class="field" style="margin-bottom:16px">
            <label style="display:block;font-size:12.5px;font-weight:600;color:var(--brand-700);margin-bottom:6px">Nominal (opsional)</label>
            <input type="text" id="nominalView" placeholder="Rp 0" autocomplete="off" inputmode="numeric"
                   style="width:100%;padding:12px 14px;border-radius:12px;border:1.5px solid var(--brand-100);font-size:16px;color:var(--brand-900)">
            <input type="hidden" name="nominal" id="nominal" value="{{ old('nominal') }}">
          </div>

          <div class="field" style="margin-bottom:20px">
            <label style="display:block;font-size:12.5px;font-weight:600;color:var(--brand-700);margin-bottom:6px">Catatan (opsional)</label>
            <textarea name="catatan" rows="3" placeholder="Catatan tambahan..."
                      style="width:100%;padding:12px 14px;border-radius:12px;border:1.5px solid var(--brand-100);font-size:16px;font-family:inherit;color:var(--brand-900)">{{ old('catatan') }}</textarea>
          </div>

          <button type="submit" class="btn-primary-custom" style="width:auto;padding:12px 24px">
            Simpan kunjungan <i class="bi bi-check2"></i>
          </button>
        </div>
      </form>
    </div>
  @endif

  @php
    $poiPickerData = $poiOptions->map(fn ($p, $i) => [
        'id' => $p->id,
        'label' => ($i + 1).'. '.$p->nama_poi,
        'lockedBy' => $p->collecting_by !== null && $p->collecting_by !== auth()->id()
            ? ['npp' => $p->collectingBy->npp ?? '-', 'nama' => $p->collectingBy->nama_lengkap ?? '-']
            : null,
    ]);
  @endphp

  @push('scripts')
  <script>
    (function () {
      var poiData = @json($poiPickerData);

      var poiInput = document.getElementById('poiInput');
      var poiHidden = document.getElementById('poiHidden');
      var poiDropdown = document.getElementById('poiDropdown');
      var poiLockedNotice = document.getElementById('poiLockedNotice');
      var poiLockedMessage = document.getElementById('poiLockedMessage');
      var poiLockedBack = document.getElementById('poiLockedBack');
      var kunjunganFormFields = document.getElementById('kunjunganFormFields');

      if (poiInput) {
        var currentVisible = [];
        var highlighted = -1;

        function renderOptions(keyword) {
          var q = keyword.toLowerCase().trim();
          currentVisible = q === '' ? poiData : poiData.filter(function (p) {
            return p.label.toLowerCase().indexOf(q) !== -1;
          });

          if (currentVisible.length === 0) {
            poiDropdown.innerHTML = '<div class="poi-option no-result">POI tidak ditemukan</div>';
          } else {
            poiDropdown.innerHTML = currentVisible.map(function (p, i) {
              var lockNote = p.lockedBy ? ' <small style="color:var(--danger)">&mdash; sedang di-collecting</small>' : '';
              return '<div class="poi-option" data-id="' + p.id + '" data-idx="' + i + '">' + escHtml(p.label) + lockNote + '</div>';
            }).join('');
          }
          highlighted = -1;
        }

        function escHtml(str) {
          return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        function selectOption(item) {
          poiInput.value = item.label;
          poiDropdown.classList.remove('open');
          highlighted = -1;

          if (item.lockedBy) {
            // Locked by another sales — show why and block the rest of the
            // form instead of letting them fill it in for a POI store()
            // would reject anyway.
            poiHidden.value = '';
            poiLockedMessage.textContent = 'Data POI ini sudah di-collecting oleh '
              + item.lockedBy.npp + ' - ' + item.lockedBy.nama + '.';
            poiLockedNotice.style.display = 'block';
            kunjunganFormFields.style.display = 'none';
          } else {
            poiHidden.value = item.id;
            poiLockedNotice.style.display = 'none';
            kunjunganFormFields.style.display = 'block';
          }
        }

        function highlightItem(idx) {
          var opts = poiDropdown.querySelectorAll('.poi-option[data-id]');
          opts.forEach(function (o) { o.classList.remove('highlighted'); });
          if (idx >= 0 && idx < opts.length) {
            opts[idx].classList.add('highlighted');
            opts[idx].scrollIntoView({block: 'nearest'});
          }
        }

        poiInput.addEventListener('focus', function () {
          renderOptions(poiInput.value);
          poiDropdown.classList.add('open');
        });
        poiInput.addEventListener('blur', function () {
          setTimeout(function () { poiDropdown.classList.remove('open'); }, 150);
        });
        poiInput.addEventListener('input', function () {
          poiHidden.value = '';
          renderOptions(poiInput.value);
          poiDropdown.classList.add('open');
        });
        poiInput.addEventListener('keydown', function (e) {
          var opts = poiDropdown.querySelectorAll('.poi-option[data-id]');
          if (!opts.length) return;
          if (e.key === 'ArrowDown') {
            e.preventDefault();
            highlighted = Math.min(highlighted + 1, opts.length - 1);
            highlightItem(highlighted);
          } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            highlighted = Math.max(highlighted - 1, 0);
            highlightItem(highlighted);
          } else if (e.key === 'Enter') {
            if (highlighted >= 0 && highlighted < currentVisible.length) {
              e.preventDefault();
              selectOption(currentVisible[highlighted]);
            }
          } else if (e.key === 'Escape') {
            poiDropdown.classList.remove('open');
          }
        });
        poiDropdown.addEventListener('mousedown', function (e) {
          var opt = e.target.closest('.poi-option[data-id]');
          if (opt) {
            var item = currentVisible[parseInt(opt.dataset.idx, 10)];
            if (item) selectOption(item);
          }
        });

        if (poiLockedBack) {
          poiLockedBack.addEventListener('click', function () {
            poiInput.value = '';
            poiHidden.value = '';
            poiLockedNotice.style.display = 'none';
            kunjunganFormFields.style.display = 'block';
            poiInput.focus();
          });
        }

        var preselected = poiData.find(function (p) { return String(p.id) === String(poiHidden.value); });
        if (preselected) poiInput.value = preselected.label;
      }

      // formKunjungan (and everything below) only renders once a kantor is
      // picked ($kantorId !== null in KunjunganController::create()) — guard
      // against the page's default landing state where none of this exists
      // yet, instead of throwing on the very first getElementById() below.
      var formKunjungan = document.getElementById('formKunjungan');
      if (formKunjungan) {
        formKunjungan.addEventListener('submit', function (e) {
          if (poiHidden && !poiHidden.value) {
            e.preventDefault();
            poiInput.style.borderColor = 'var(--danger)';
            poiInput.placeholder = 'Pilih POI dari daftar!';
            poiInput.focus();
          }
        });

        // Hasil = Closing -> show + require "Status Mitra Baru".
        var hasilSelect = document.getElementById('hasilSelect');
        var statusMitraField = document.getElementById('statusMitraField');
        var statusMitraSelect = document.getElementById('statusMitraSelect');

        var toggleStatusMitra = function () {
          var isClosing = hasilSelect.value === 'Closing';
          statusMitraField.style.display = isClosing ? 'block' : 'none';
          statusMitraSelect.required = isClosing;
        };
        hasilSelect.addEventListener('change', toggleStatusMitra);
        toggleStatusMitra();

        // Rupiah-formatted nominal input.
        var view = document.getElementById('nominalView');
        var raw = document.getElementById('nominal');
        if (raw.value) view.value = 'Rp ' + new Intl.NumberFormat('id-ID').format(raw.value);
        view.addEventListener('input', function () {
          var angka = this.value.replace(/[^0-9]/g, '');
          if (angka === '') {
            raw.value = '';
            this.value = '';
            return;
          }
          raw.value = angka;
          this.value = 'Rp ' + new Intl.NumberFormat('id-ID').format(angka);
        });
      }
    })();
  </script>
  @endpush
@endsection
