@php
  $selectStyle = 'width:100%;padding:12px 14px;border-radius:12px;border:1.5px solid var(--brand-100);font-size:16px;background:#fff;color:var(--brand-900);outline:none;appearance:none';
  $old = fn ($key, $default = null) => old($key, $poi->{$key} ?? $default);
@endphp

<div class="field">
  <label>Nama POI</label>
  <div class="input-wrap">
    <i class="bi bi-shop leading"></i>
    <input type="text" name="nama_poi" value="{{ $old('nama_poi') }}" placeholder="Nama tempat usaha / mitra" required autofocus>
  </div>
</div>

<div class="field">
  <label>Alamat</label>
  <div class="input-wrap">
    <i class="bi bi-geo-alt leading"></i>
    <input type="text" name="alamat" value="{{ $old('alamat') }}" placeholder="Alamat lengkap POI" required>
  </div>
</div>

<div class="field">
  <label>Kantor</label>
  <select name="kantor_id" required style="{{ $selectStyle }}">
    <option value="" disabled @selected(! $old('kantor_id'))>Pilih kantor&hellip;</option>
    @foreach ($kantorOptions as $kantor)
      <option value="{{ $kantor->id }}" @selected((string) $old('kantor_id') === (string) $kantor->id)>{{ $kantor->nama }}</option>
    @endforeach
  </select>
</div>

<div class="field">
  <label>Sektor <small style="font-weight:400;color:#8A6B55">(pilih dari saran atau ketik sendiri)</small></label>
  <div class="input-wrap">
    <i class="bi bi-diagram-3 leading"></i>
    <input type="text" name="sektor" list="sektorSuggestions" value="{{ $old('sektor') }}" placeholder="Contoh: Retail & Shopping" required>
  </div>
  <datalist id="sektorSuggestions">
    @foreach ($sektorOptions as $sektor)
      <option value="{{ $sektor }}"></option>
    @endforeach
  </datalist>
</div>

<div class="field">
  <label>Sub Sektor <small style="font-weight:400;color:#8A6B55">(opsional)</small></label>
  <div class="input-wrap">
    <i class="bi bi-tag leading"></i>
    <input type="text" name="sub_sektor" value="{{ $old('sub_sektor') }}" placeholder="Contoh: RESTAURANT, CAFE, PHARMACY">
  </div>
</div>

<div class="field">
  <label>Area <small style="font-weight:400;color:#8A6B55">(opsional &mdash; pilih dari saran atau ketik sendiri)</small></label>
  <div class="input-wrap">
    <i class="bi bi-geo leading"></i>
    <input type="text" name="area" list="areaSuggestions" value="{{ $old('area') }}" placeholder="Contoh: Ring 1 (0 - 1 Km)">
  </div>
  <datalist id="areaSuggestions">
    @foreach ($areaOptions as $area)
      <option value="{{ $area }}"></option>
    @endforeach
  </datalist>
</div>

<div class="field">
  <label>Status Mitra</label>
  <select name="status_mitra" required style="{{ $selectStyle }}">
    <option value="" disabled @selected(! $old('status_mitra'))>Pilih status mitra&hellip;</option>
    @foreach ($statusMitraOptions as $statusMitra)
      <option value="{{ $statusMitra }}" @selected($old('status_mitra') === $statusMitra)>{{ $statusMitra }}</option>
    @endforeach
  </select>
</div>

<div class="field">
  <label>PIC <small style="font-weight:400;color:#8A6B55">(opsional)</small></label>
  <div class="input-wrap">
    <i class="bi bi-person leading"></i>
    <input type="text" name="pic" value="{{ $old('pic') }}" placeholder="Contoh: Branch Manager">
  </div>
</div>
