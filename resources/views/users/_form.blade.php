@php
  $selectStyle = 'width:100%;padding:12px 14px;border-radius:12px;border:1.5px solid var(--brand-100);font-size:14px;background:#fff;color:var(--brand-900);outline:none;appearance:none';
  $old = fn ($key, $default = null) => old($key, $user->{$key} ?? $default);
  $oldKantorIds = old('kantor_ids', $user ? $user->kantor->pluck('id')->all() : []);
  $oldUnitId = old('unit_id', $user->unit_id ?? null);
@endphp

@if (! $user)
  <div class="field">
    <label>NPP</label>
    <div class="input-wrap">
      <i class="bi bi-person-badge leading"></i>
      <input type="text" name="npp" value="{{ old('npp') }}" placeholder="Nomor pegawai (jadi username login)" required autofocus>
    </div>
    <small style="color:#8A6B55">Password awal otomatis = NPP. User wajib ganti password saat login pertama.</small>
  </div>
@else
  <div class="field">
    <label>NPP</label>
    <div class="input-wrap">
      <i class="bi bi-person-badge leading"></i>
      <input type="text" value="{{ $user->npp }}" disabled style="background:#F5EFE8">
    </div>
    <small style="color:#8A6B55">NPP adalah username login dan tidak dapat diubah.</small>
  </div>
@endif

<div class="field">
  <label>Nama Lengkap</label>
  <div class="input-wrap">
    <i class="bi bi-person leading"></i>
    <input type="text" name="nama_lengkap" value="{{ $old('nama_lengkap') }}" placeholder="Nama sesuai data HR" required>
  </div>
</div>

<div class="field">
  <label>
    Unit / Jabatan <small style="font-weight:400;color:#8A6B55">(opsional &mdash; cuma ditampilkan, tidak mempengaruhi hak akses. Kelola daftar unit di menu Pengaturan)</small>
  </label>
  <select name="unit_id" style="{{ $selectStyle }}">
    <option value="">&mdash; Tidak diisi &mdash;</option>
    @foreach ($unitOptions as $unit)
      <option value="{{ $unit->id }}" @selected((string) $oldUnitId === (string) $unit->id)>{{ $unit->nama }}</option>
    @endforeach
  </select>
</div>

<div class="field">
  <label>Role Sistem</label>
  <select name="role" required style="{{ $selectStyle }}">
    <option value="" disabled @selected(! $old('role'))>Pilih role&hellip;</option>
    @foreach ($roleOptions as $role)
      <option value="{{ $role }}" @selected($old('role') === $role)>{{ $role }}</option>
    @endforeach
  </select>
  <small style="color:#8A6B55">Role menentukan hak akses di sistem (admin / admin_final / sales).</small>
</div>

<div class="field">
  <label>Kantor <small style="font-weight:400;color:#8A6B55">(wajib minimal 1, kecuali role admin)</small></label>
  <div style="display:flex;flex-direction:column;gap:6px;max-height:220px;overflow-y:auto;border:1.5px solid var(--brand-100);border-radius:12px;padding:12px">
    @forelse ($kantorOptions as $kantor)
      <label style="display:flex;align-items:center;gap:8px;font-size:13.5px;font-weight:400">
        <input type="checkbox" name="kantor_ids[]" value="{{ $kantor->id }}" @checked(in_array($kantor->id, $oldKantorIds))>
        {{ $kantor->nama }}
      </label>
    @empty
      <span style="font-size:13px;color:#8A6B55">Belum ada data kantor.</span>
    @endforelse
  </div>
</div>
