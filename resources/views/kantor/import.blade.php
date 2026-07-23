@extends('layouts.app')

@section('title', 'Import Cabang')
@section('breadcrumb', 'Pengaturan / Import Cabang')

@section('content')
  <div class="panel" style="max-width:760px">
    <div class="panel-head"><h3>Bulk Import/Update Cabang</h3></div>

    @if ($errors->any())
      <div class="form-error">{{ $errors->first() }}</div>
    @endif

    <p style="font-size:12.5px;color:#8A6B55;margin-top:-8px;margin-bottom:18px">
      Kolom yang dikenali: <b>ID</b> (opsional), <b>Kode</b>, <b>Cabang</b>, <b>Area</b>,
      <b>Cabang-Cluster</b>, <b>Aktif</b>. Baris dengan ID akan MENGUBAH Cabang yang sama.
      Baris tanpa ID tapi nama Cabang-nya cocok sama data yang sudah ada juga otomatis
      MENGUBAH (bukan bikin baru) &mdash; jadi file mapping Cabang/Cabang-Cluster/Area yang
      sudah lo punya bisa langsung diupload apa adanya. Baris yang nama Cabang-nya sama
      sekali baru akan ditambahkan sebagai Cabang baru. Belum punya file dasar?
      <a href="{{ route('export.kantor.download') }}" style="color:var(--brand-500)">Export dulu</a>.
    </p>

    <form method="POST" action="{{ route('kantor.import.store') }}" enctype="multipart/form-data">
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
            <div class="lbl">Baris berhasil diimport</div>
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
              Baris ini gagal disimpan karena error teknis (misalnya Kode/Nama sudah dipakai Cabang
              lain) &mdash; nomor barisnya tidak tercatat sistem, tapi detail lengkapnya sudah masuk
              ke log server buat dicek developer.
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
