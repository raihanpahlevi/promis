@extends('layouts.app')

@section('title', 'Import POI')
@section('breadcrumb', 'Data POI / Import Excel')

@section('content')
  <div class="panel" style="max-width:760px">
    <div class="panel-head"><h3>Bulk Import Data POI</h3></div>

    @if ($errors->any())
      <div class="form-error">{{ $errors->first() }}</div>
    @endif

    <a href="{{ route('poi.import.template') }}" style="display:inline-flex;align-items:center;gap:6px;font-size:12.5px;color:var(--brand-500);text-decoration:none;margin-bottom:18px">
      <i class="bi bi-download"></i> Unduh template kosong (Template_Import_POI_PROMIS.xlsx)
    </a>

    <form method="POST" action="{{ route('poi.import.store') }}" enctype="multipart/form-data">
      @csrf
      <div class="field">
        <label>File Excel (.xlsx / .xls)</label>
        <input type="file" name="file" accept=".xlsx,.xls" required
          style="width:100%;padding:10px;border-radius:12px;border:1.5px solid var(--brand-100);font-size:13.5px;background:#fff">
      </div>
      <button type="submit" class="btn-primary-custom" style="width:auto;padding:12px 22px">
        <i class="bi bi-upload"></i> Import
      </button>
      <p style="font-size:12px;color:#8A6B55;margin:10px 0 0">
        File langsung masuk antrian dan diproses di latar belakang — tidak perlu menunggu di halaman ini.
      </p>
    </form>
  </div>

  <div style="max-width:760px">
    @include('partials.import-jobs', ['recentJobs' => $recentJobs])
  </div>
@endsection
