@props(['paginator', 'label', 'pageSizeLabel', 'pageSizeOption'])

{{-- UI Kit list footer: row summary, previous/next and page links (query string kept by the paginator). The page size is shown, not changeable. --}}
<div class="mk-table-foot" data-ui-live-region="foot">
    <span class="mk-footnote" role="status">{{ __('lf.LF_common_pagination_summary', ['from' => $paginator->firstItem() ?? 0, 'to' => $paginator->lastItem() ?? 0, 'total' => $paginator->total()]) }}</span>
    <nav class="mk-pages" aria-label="{{ $label }}">
        @if ($paginator->onFirstPage())
            <button type="button" class="mk-page" disabled aria-label="{{ html_entity_decode(__('pagination.previous'), ENT_QUOTES, 'UTF-8') }}">‹</button>
        @else
            <a class="mk-page" rel="prev" href="{{ $paginator->previousPageUrl() }}" aria-label="{{ html_entity_decode(__('pagination.previous'), ENT_QUOTES, 'UTF-8') }}">‹</a>
        @endif
        @foreach ($paginator->getUrlRange(max(1, $paginator->currentPage() - 2), min($paginator->lastPage(), $paginator->currentPage() + 2)) as $page => $url)
            <a class="mk-page" href="{{ $url }}" @if ($page === $paginator->currentPage()) aria-current="page" @endif>{{ $page }}</a>
        @endforeach
        @if ($paginator->hasMorePages())
            <a class="mk-page" rel="next" href="{{ $paginator->nextPageUrl() }}" aria-label="{{ html_entity_decode(__('pagination.next'), ENT_QUOTES, 'UTF-8') }}">›</a>
        @else
            <button type="button" class="mk-page" disabled aria-label="{{ html_entity_decode(__('pagination.next'), ENT_QUOTES, 'UTF-8') }}">›</button>
        @endif
        <select aria-label="{{ $pageSizeLabel }}" disabled>
            <option>{{ $pageSizeOption }}</option>
        </select>
    </nav>
</div>
