@extends('layouts.app')

@section('title', 'Tambah POI')
@section('breadcrumb', 'Data POI / Tambah')

@section('content')
  <div class="panel" style="max-width:640px">
    @if ($errors->any())
      <div class="form-error">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('poi.store') }}">
      @csrf
      @include('poi._form', ['poi' => null])

      <div style="display:flex;gap:10px;margin-top:20px">
        <button type="submit" class="btn-primary-custom" style="width:auto;padding:12px 22px">
          <i class="bi bi-check2"></i> Simpan POI
        </button>
        <a href="{{ route('poi.index') }}" class="btn-primary-custom" style="width:auto;padding:12px 22px;text-decoration:none;background:transparent;color:var(--brand-700);border:1.5px solid var(--brand-100)">
          Batal
        </a>
      </div>
    </form>
  </div>
@endsection
