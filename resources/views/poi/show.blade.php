@extends('layouts.app')

@section('title', 'Detail POI')
@section('breadcrumb', 'Data POI / Detail')

@section('content')
  <div class="grid-2">
    <div class="panel">
      <div class="panel-head">
        <h3>{{ $poi->nama_poi }}</h3>
        @if ($poi->status === 'aktif')
          <span class="badge badge-ok">Aktif</span>
        @else
          <span class="badge badge-no">Nonaktif</span>
        @endif
      </div>

      @if (session('status'))
        <div class="form-status">{{ session('status') }}</div>
      @endif

      <div class="detail-ledger">
        <div class="detail-row">
          <span>Alamat</span>
          <span>
            @if ($poi->alamat)
              <a class="addr-link" href="https://www.google.com/maps/search/?api=1&query={{ urlencode($poi->alamat) }}" target="_blank" rel="noopener noreferrer" title="Buka di Google Maps">
                <i class="bi bi-geo-alt"></i>{{ $poi->alamat }}
              </a>
            @else
              -
            @endif
          </span>
        </div>
        <div class="detail-row"><span>Kantor</span><span>{{ $poi->kantor->nama ?? '-' }}</span></div>
        <div class="detail-row"><span>Sektor</span><span>{{ $poi->sektor }}</span></div>
        <div class="detail-row"><span>Sub Sektor</span><span>{{ $poi->sub_sektor ?? '-' }}</span></div>
        <div class="detail-row"><span>Area</span><span>{{ $poi->area ?? '-' }}</span></div>
        <div class="detail-row"><span>Status Mitra</span><span><span class="badge {{ $poi->statusMitraBadgeClass() }}">{{ $poi->status_mitra }}</span></span></div>
        <div class="detail-row"><span>PIC</span><span>{{ $poi->pic ?? '-' }}</span></div>
        <div class="detail-row"><span>Nomor Rekening/CIF</span><span>{{ $poi->norek_cif ?? '-' }}</span></div>
        <div class="detail-row">
          <span>Status Geocode</span>
          <span>
            @if ($poi->geocode_status === 'success')
              <span class="badge badge-ok">Success</span>
            @elseif ($poi->geocode_status === 'failed')
              <span class="badge badge-no">Failed</span>
            @else
              <span class="badge badge-pending">Pending</span>
            @endif
          </span>
        </div>
        <div class="detail-row"><span>Dibuat oleh</span><span>{{ $poi->createdBy->nama_lengkap ?? '-' }}</span></div>
        <div class="detail-row"><span>Dibuat pada</span><span class="num-tabular">{{ $poi->created_at->format('d M Y H:i') }}</span></div>
      </div>

      <div style="display:flex;gap:10px;margin-top:20px">
        <a href="{{ route('poi.index') }}" class="btn-primary-custom" style="width:auto;padding:10px 18px;text-decoration:none;background:transparent;color:var(--brand-700);border:1.5px solid var(--brand-100)">
          <i class="bi bi-arrow-left"></i> Kembali ke daftar
        </a>
        @if ($canManage)
          <a href="{{ route('poi.edit', $poi) }}" class="btn-primary-custom" style="width:auto;padding:10px 18px;text-decoration:none">
            <i class="bi bi-pencil"></i> Edit POI
          </a>
        @endif
      </div>
    </div>

    <div class="table-panel">
      <div class="panel-head"><h3>Riwayat Hapus / Reopen</h3></div>
      @if ($poi->reopenLogs->isEmpty())
        <div class="empty-state-rich">
          <i class="bi bi-clock-history"></i>
          <p>Belum ada riwayat untuk POI ini.</p>
          <small>Aksi hapus atau reopen akan tercatat di sini.</small>
        </div>
      @else
        <table class="table-ledger">
          <thead>
            <tr>
              <th>Tanggal</th>
              <th>Aksi</th>
              <th>Alasan</th>
              <th>Oleh</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($poi->reopenLogs as $log)
              <tr>
                <td>{{ $log->created_at->format('d M Y H:i') }}</td>
                <td>
                  @if ($log->action === 'hapus')
                    <span class="badge badge-no">Hapus</span>
                  @else
                    <span class="badge badge-ok">Reopen</span>
                  @endif
                </td>
                <td>{{ $log->alasan }}</td>
                <td>{{ $log->user->nama_lengkap ?? '-' }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      @endif
    </div>
  </div>
@endsection
