@extends('layouts.app')

@section('title', 'Manajemen User')
@section('breadcrumb', 'Manajemen User / Daftar')

@section('content')
  <div class="table-panel">
    <div class="panel-head" style="flex-wrap:wrap;gap:12px">
      <h3>Daftar User</h3>
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <a href="{{ route('user.import.create') }}" class="btn-primary-custom" style="text-decoration:none;background:transparent;color:var(--brand-700);border:1.5px solid var(--brand-100);padding:9px 16px">
          <i class="bi bi-upload"></i> Import Excel
        </a>
        <a href="{{ route('user.create') }}" class="btn-primary-custom" style="text-decoration:none;padding:9px 16px">
          <i class="bi bi-plus-lg"></i> Tambah User
        </a>
      </div>
    </div>

    @if (session('status'))
      <div class="form-status">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
      <div class="form-error">{{ $errors->first() }}</div>
    @endif

    <form method="GET" action="{{ route('user.index') }}" style="margin-bottom:16px">
      <div class="panel-head" style="flex-wrap:wrap;gap:10px;margin-bottom:0">
        <div class="search-box" style="width:260px">
          <i class="bi bi-search"></i>
          <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Cari NPP atau nama...">
        </div>
        <div class="filters" style="flex-wrap:wrap">
          <select name="role" onchange="this.form.submit()">
            <option value="">Semua Role</option>
            @foreach ($roleOptions as $role)
              <option value="{{ $role }}" @selected(($filters['role'] ?? '') === $role)>{{ $role }}</option>
            @endforeach
          </select>
          @if ($kantorOptions->isNotEmpty())
            <select name="kantor" onchange="this.form.submit()">
              <option value="">Semua Cabang</option>
              @foreach ($kantorOptions as $kantor)
                <option value="{{ $kantor->id }}" @selected((string) ($filters['kantor'] ?? '') === (string) $kantor->id)>{{ $kantor->nama }}</option>
              @endforeach
            </select>
          @endif
          <select name="is_active" onchange="this.form.submit()">
            <option value="">Semua Status</option>
            <option value="1" @selected(($filters['is_active'] ?? '') === '1')>Aktif</option>
            <option value="0" @selected(($filters['is_active'] ?? '') === '0')>Nonaktif</option>
          </select>
          <button type="submit" class="btn-primary-custom" style="padding:10px 16px;font-size:13px;width:auto">Terapkan</button>
          @if (array_filter($filters ?? []))
            <a href="{{ route('user.index') }}" style="align-self:center;font-size:12px;color:var(--brand-500);text-decoration:none">Reset</a>
          @endif
        </div>
      </div>
    </form>

    @if ($users->isEmpty())
      <div class="empty-state-rich">
        <i class="bi bi-people"></i>
        <p>Belum ada user yang cocok dengan filter ini.</p>
        <small>Coba ubah atau reset filter di atas, atau tambah user baru.</small>
      </div>
    @else
      <div style="overflow-x:auto">
        <table class="table-ledger table-responsive-stack">
          <thead>
            <tr>
              <th>NPP</th>
              <th>Nama Lengkap</th>
              <th>Unit / Jabatan</th>
              <th>Role</th>
              <th>Kantor</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            @foreach ($users as $u)
              <tr>
                <td class="cell-heading"><b>{{ $u->npp }}</b></td>
                <td data-label="Nama Lengkap">{{ $u->nama_lengkap }}</td>
                <td data-label="Unit / Jabatan">{{ $u->unit->nama ?? '-' }}</td>
                <td data-label="Role">{{ $u->role }}</td>
                <td data-label="Kantor">{{ $u->kantor->pluck('nama')->join(', ') ?: '-' }}</td>
                <td data-label="Status">
                  @if ($u->is_active)
                    <span class="badge badge-ok">Aktif</span>
                  @else
                    <span class="badge badge-no">Nonaktif</span>
                  @endif
                </td>
                <td class="cell-actions">
                  <a href="{{ route('user.edit', $u) }}" class="action-link"><i class="bi bi-pencil"></i> Edit</a>
                  @if ($u->id !== auth()->id())
                    <form method="POST" action="{{ route('user.toggle-active', $u) }}" onsubmit="return confirm('{{ $u->is_active ? 'Nonaktifkan' : 'Aktifkan kembali' }} user ini?');" style="display:inline">
                      @csrf
                      <button type="submit" class="action-link{{ $u->is_active ? ' danger' : '' }}" style="background:none;border:none;font:inherit;cursor:pointer">
                        @if ($u->is_active)
                          <i class="bi bi-x-octagon"></i> Nonaktifkan
                        @else
                          <i class="bi bi-arrow-counterclockwise"></i> Aktifkan
                        @endif
                      </button>
                    </form>
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>

      @include('partials.pagination', ['paginator' => $users])
    @endif
  </div>
@endsection
