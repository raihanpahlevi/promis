{{--
  Riwayat Import — status list for background (queued) imports.
  Expects: $recentJobs (Collection of App\Models\ImportJob, newest first).
  Auto-reloads the page while any job is still pending/processing so the
  uploader sees pending -> processing -> done without touching anything.
--}}
@php($hasActive = $recentJobs->contains(fn ($j) => $j->isActive()))

<div class="table-panel" style="margin-top:24px">
  <div class="panel-head">
    <h3>Riwayat Import</h3>
    @if ($hasActive)
      <span class="badge badge-pending"><i class="bi bi-arrow-repeat"></i> Ada import berjalan&hellip;</span>
    @endif
  </div>

  @if ($recentJobs->isEmpty())
    <div class="empty-state-rich">
      <i class="bi bi-inbox"></i>
      <p>Belum ada import.</p>
      <small>Upload file lewat form di atas — statusnya bakal muncul di sini.</small>
    </div>
  @else
    <div style="overflow-x:auto">
      <table class="table-ledger table-responsive-stack">
        <thead>
          <tr>
            <th>Waktu</th>
            <th>File</th>
            <th>Oleh</th>
            <th>Status</th>
            <th class="num" style="text-align:right">Masuk</th>
            <th class="num" style="text-align:right">Ditolak</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($recentJobs as $job)
            <tr>
              <td class="cell-heading">{{ $job->created_at->format('d M H:i') }}</td>
              <td data-label="File">{{ $job->original_filename }}</td>
              <td data-label="Oleh">{{ $job->creator->nama_lengkap ?? '-' }}</td>
              <td data-label="Status">
                @if ($job->status === \App\Models\ImportJob::STATUS_DONE)
                  <span class="badge badge-ok">Selesai</span>
                @elseif ($job->status === \App\Models\ImportJob::STATUS_FAILED)
                  <span class="badge badge-no">Gagal</span>
                @elseif ($job->status === \App\Models\ImportJob::STATUS_PROCESSING)
                  <span class="badge badge-pending">Sedang diproses</span>
                @else
                  <span class="badge" style="background:var(--brand-50);color:var(--brand-700)">Menunggu antrian</span>
                @endif
              </td>
              <td data-label="Masuk" class="num" style="text-align:right"><strong>{{ number_format($job->imported_count) }}</strong></td>
              <td data-label="Ditolak" class="num" style="text-align:right">{{ number_format($job->rejected_count) }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>

    @php($latest = $recentJobs->first())
    @if ($latest && $latest->status === \App\Models\ImportJob::STATUS_FAILED && ! empty($latest->result_summary['error']))
      <div class="form-error" style="margin-top:14px;margin-bottom:0">
        Import terakhir gagal total: {{ $latest->result_summary['error'] }}
      </div>
    @endif
    @if ($latest && $latest->status === \App\Models\ImportJob::STATUS_DONE && ! empty($latest->result_summary['reasons']))
      <div style="margin-top:16px">
        <div class="panel-head"><h3>Alasan Penolakan (import terakhir)</h3></div>
        <table class="table-ledger">
          <thead>
            <tr><th>Alasan</th><th class="num" style="text-align:right">Jumlah Baris</th></tr>
          </thead>
          <tbody>
            @foreach (array_slice($latest->result_summary['reasons'], 0, 15, true) as $reason => $count)
              <tr>
                <td>{{ $reason }}</td>
                <td class="num" style="text-align:right"><strong>{{ $count }}</strong></td>
              </tr>
            @endforeach
          </tbody>
        </table>
        @if (count($latest->result_summary['reasons']) > 15)
          <p style="font-size:12px;color:#8A6B55;margin:10px 0 0">
            +{{ count($latest->result_summary['reasons']) - 15 }} alasan lain — detail lengkap ada di log server.
          </p>
        @endif
      </div>
    @endif
    @if ($latest && $latest->status === \App\Models\ImportJob::STATUS_DONE && ! empty($latest->result_summary['technical_errors']))
      <div style="margin-top:16px">
        <div class="panel-head"><h3>Gagal Teknis (Bukan Validasi)</h3></div>
        <ul style="margin:0;padding-left:18px;font-size:12.5px;color:var(--danger)">
          @foreach ($latest->result_summary['technical_errors'] as $message)
            <li>{{ $message }}</li>
          @endforeach
        </ul>
      </div>
    @endif
  @endif
</div>

@if ($hasActive)
  @push('scripts')
  <script>
    // Poll while an import is running — plain reload keeps this dependency-free
    // and the page is cheap to render.
    setTimeout(function () { window.location.reload(); }, 8000);
  </script>
  @endpush
@endif
