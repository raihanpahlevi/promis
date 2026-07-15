@extends('layouts.app')

@section('title', 'Import User')
@section('breadcrumb', 'Manajemen User / Import Excel')

@section('content')
  <div class="panel" style="max-width:760px">
    <div class="panel-head"><h3>Bulk Import User</h3></div>

    @if ($errors->any())
      <div class="form-error">{{ $errors->first() }}</div>
    @endif

    <a href="{{ route('user.import.template') }}" style="display:inline-flex;align-items:center;gap:6px;font-size:12.5px;color:var(--brand-500);text-decoration:none;margin-bottom:18px">
      <i class="bi bi-download"></i> Unduh template kosong (Template_Import_User_PROMIS.xlsx)
    </a>

    <form method="POST" action="{{ route('user.import.store') }}" enctype="multipart/form-data">
      @csrf
      <div class="field">
        <label>File Excel (.xlsx / .xls)</label>
        <input type="file" name="file" accept=".xlsx,.xls" required
          style="width:100%;padding:10px;border-radius:12px;border:1.5px solid var(--brand-100);font-size:13.5px;background:#fff">
      </div>
      <button type="submit" class="btn-primary-custom" style="width:auto;padding:12px 22px">
        <i class="bi bi-upload"></i> Import Sekarang
      </button>
    </form>

    @if (session('import_summary'))
      @php($summary = session('import_summary'))
      <div style="margin-top:28px">
        <div class="stat-grid" style="grid-template-columns:repeat(2,1fr)">
          <div class="stat-card accent-ok">
            <span class="kicker"><i class="bi bi-check2-circle"></i> Berhasil diimport</span>
            <div class="num" style="margin-top:8px">{{ $summary['imported'] }}</div>
            <div class="lbl">User berhasil diimport</div>
          </div>
          <div class="stat-card accent-danger">
            <span class="kicker"><i class="bi bi-x-octagon"></i> Ditolak</span>
            <div class="num" style="margin-top:8px">{{ $summary['rejected'] }}</div>
            <div class="lbl">Baris ditolak</div>
          </div>
        </div>

        @if (! empty($summary['errors']))
          <div class="table-panel" style="margin-top:16px">
            <div class="panel-head"><h3>Detail Baris Ditolak</h3></div>
            <table class="table-ledger">
              <thead>
                <tr>
                  <th>Baris</th>
                  <th>Kolom</th>
                  <th>Alasan</th>
                </tr>
              </thead>
              <tbody>
                @foreach ($summary['errors'] as $error)
                  <tr>
                    <td>{{ $error['row'] }}</td>
                    <td>{{ $error['attribute'] }}</td>
                    <td>{{ implode(' ', $error['errors']) }}</td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
        @endif

        @if (! empty($summary['technical_errors']))
          <div class="table-panel" style="margin-top:16px">
            <div class="panel-head"><h3>Gagal Teknis (Bukan Validasi)</h3></div>
            <p style="font-size:12.5px;color:#8A6B55;margin-top:-8px">
              Baris ini gagal disimpan karena error teknis (bukan salah isi data) &mdash; nomor barisnya tidak
              tercatat sistem, tapi detail lengkapnya sudah masuk ke log server buat dicek developer.
            </p>
            <ul style="margin:0;padding-left:18px;font-size:12.5px;color:var(--danger)">
              @foreach ($summary['technical_errors'] as $message)
                <li>{{ $message }}</li>
              @endforeach
            </ul>
          </div>
        @endif
      </div>
    @endif
  </div>
@endsection
