@extends('layouts.app')

@section('title', 'Data POI')
@section('breadcrumb', 'Data POI / Daftar')

@section('content')
  <div class="table-panel">
    <div class="panel-head" style="flex-wrap:wrap;gap:12px">
      <h3>Daftar POI</h3>
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        @if ($canManage)
          <a href="{{ route('poi.import.create') }}" class="btn-primary-custom" style="text-decoration:none;background:transparent;color:var(--brand-700);border:1.5px solid var(--brand-100);padding:9px 16px">
            <i class="bi bi-upload"></i> Import Excel
          </a>
          <a href="{{ route('poi.create') }}" class="btn-primary-custom" style="text-decoration:none;padding:9px 16px">
            <i class="bi bi-plus-lg"></i> Tambah POI
          </a>
        @endif
      </div>
    </div>

    @if (session('status'))
      <div class="form-status">{{ session('status') }}</div>
    @endif

    <form method="GET" action="{{ route('poi.index') }}" style="margin-bottom:16px">
      <div class="panel-head" style="flex-wrap:wrap;gap:10px;margin-bottom:0">
        <div class="search-box" style="width:260px">
          <i class="bi bi-search"></i>
          <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Cari nama, alamat, atau PIC...">
        </div>
        <div class="filters" style="flex-wrap:wrap">
          @if ($kantorOptions->isNotEmpty())
            <select name="kantor" onchange="this.form.submit()">
              <option value="">Semua Kantor</option>
              @foreach ($kantorOptions as $kantor)
                <option value="{{ $kantor->id }}" @selected((string) ($filters['kantor'] ?? '') === (string) $kantor->id)>{{ $kantor->nama }}</option>
              @endforeach
            </select>
          @endif
          <select name="status" onchange="this.form.submit()">
            <option value="">Semua Status</option>
            <option value="aktif" @selected(($filters['status'] ?? '') === 'aktif')>Aktif</option>
            <option value="nonaktif" @selected(($filters['status'] ?? '') === 'nonaktif')>Nonaktif</option>
          </select>
          <select name="area" onchange="this.form.submit()">
            <option value="">Semua Area</option>
            @foreach ($areaOptions as $area)
              <option value="{{ $area }}" @selected(($filters['area'] ?? '') === $area)>{{ $area }}</option>
            @endforeach
          </select>
          <select name="sektor" onchange="this.form.submit()">
            <option value="">Semua Sektor</option>
            @foreach ($sektorOptions as $sektor)
              <option value="{{ $sektor }}" @selected(($filters['sektor'] ?? '') === $sektor)>{{ $sektor }}</option>
            @endforeach
          </select>
          <select name="status_mitra" onchange="this.form.submit()">
            <option value="">Semua Status Mitra</option>
            @foreach ($statusMitraOptions as $statusMitra)
              <option value="{{ $statusMitra }}" @selected(($filters['status_mitra'] ?? '') === $statusMitra)>{{ $statusMitra }}</option>
            @endforeach
          </select>
          <button type="submit" class="btn-primary-custom" style="padding:7px 14px;font-size:12px;width:auto">Terapkan</button>
          @if (array_filter($filters ?? []))
            <a href="{{ route('poi.index') }}" style="align-self:center;font-size:12px;color:var(--brand-500);text-decoration:none">Reset</a>
          @endif
        </div>
      </div>
    </form>

    @if ($pois->isEmpty())
      <div class="empty-state-rich">
        <i class="bi bi-geo"></i>
        <p>Belum ada data POI yang cocok dengan filter ini.</p>
        <small>Coba ubah atau reset filter di atas, atau tambah POI baru.</small>
      </div>
    @else
      <div style="overflow-x:auto">
        <table class="table-ledger">
          <thead>
            <tr>
              <th>Nama POI</th>
              <th>Kantor</th>
              <th>Sektor</th>
              <th>Area</th>
              <th>Status Mitra</th>
              <th>PIC</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            @foreach ($pois as $poi)
              <tr>
                <td><b>{{ $poi->nama_poi }}</b><br><small style="color:#8A6B55">{{ \Illuminate\Support\Str::limit($poi->alamat, 50) }}</small></td>
                <td>{{ $poi->kantor->nama ?? '-' }}</td>
                <td>{{ $poi->sektor }}</td>
                <td>{{ $poi->area ?? '-' }}</td>
                <td><span class="badge {{ $poi->statusMitraBadgeClass() }}">{{ $poi->status_mitra }}</span></td>
                <td>{{ $poi->pic ?? '-' }}</td>
                <td>
                  <a href="{{ route('poi.show', $poi) }}" style="color:var(--brand-500);text-decoration:none;font-size:12px"><i class="bi bi-eye"></i> Detail</a>
                  @if ($canManage)
                    &nbsp;·&nbsp;
                    <a href="{{ route('poi.edit', $poi) }}" style="color:var(--brand-500);text-decoration:none;font-size:12px"><i class="bi bi-pencil"></i> Edit</a>
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>

      @include('partials.pagination', ['paginator' => $pois])
    @endif
  </div>
@endsection
