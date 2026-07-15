@if ($paginator->total() > 0)
  <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:16px;padding-top:16px;border-top:1px solid var(--brand-100);flex-wrap:wrap">
    <div class="text-muted" style="font-size:12px">
      Menampilkan <span class="num-tabular">{{ $paginator->firstItem() }}</span>&ndash;<span class="num-tabular">{{ $paginator->lastItem() }}</span> dari <span class="num-tabular">{{ $paginator->total() }}</span> data
    </div>
    @if ($paginator->hasPages())
    <div style="display:flex;gap:6px;flex-wrap:wrap">
      @if ($paginator->onFirstPage())
        <span style="padding:6px 12px;border-radius:8px;font-size:12px;color:var(--brand-400);border:1px solid var(--brand-100)">&laquo; Sebelumnya</span>
      @else
        <a href="{{ $paginator->previousPageUrl() }}" style="padding:6px 12px;border-radius:8px;font-size:12px;color:var(--brand-700);border:1px solid var(--brand-100);text-decoration:none;transition:background .15s" onmouseover="this.style.background='var(--brand-50)'" onmouseout="this.style.background=''">&laquo; Sebelumnya</a>
      @endif

      @foreach ($paginator->getUrlRange(max(1, $paginator->currentPage() - 2), min($paginator->lastPage(), $paginator->currentPage() + 2)) as $page => $url)
        @if ($page == $paginator->currentPage())
          <span class="num-tabular" style="padding:6px 12px;border-radius:8px;font-size:12px;font-weight:700;background:var(--brand-700);color:#fff">{{ $page }}</span>
        @else
          <a href="{{ $url }}" class="num-tabular" style="padding:6px 12px;border-radius:8px;font-size:12px;color:var(--brand-700);border:1px solid var(--brand-100);text-decoration:none;transition:background .15s" onmouseover="this.style.background='var(--brand-50)'" onmouseout="this.style.background=''">{{ $page }}</a>
        @endif
      @endforeach

      @if ($paginator->hasMorePages())
        <a href="{{ $paginator->nextPageUrl() }}" style="padding:6px 12px;border-radius:8px;font-size:12px;color:var(--brand-700);border:1px solid var(--brand-100);text-decoration:none;transition:background .15s" onmouseover="this.style.background='var(--brand-50)'" onmouseout="this.style.background=''">Berikutnya &raquo;</a>
      @else
        <span style="padding:6px 12px;border-radius:8px;font-size:12px;color:var(--brand-400);border:1px solid var(--brand-100)">Berikutnya &raquo;</span>
      @endif
    </div>
    @endif
  </div>
@endif
