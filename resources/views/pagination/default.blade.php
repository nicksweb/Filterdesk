@if ($paginator->hasPages())
    <nav class="pager" role="navigation" aria-label="Pagination">
        <span class="pager-summary">{{ number_format($paginator->firstItem()) }}–{{ number_format($paginator->lastItem()) }} of {{ number_format($paginator->total()) }}</span>
        <span class="pager-links">
            @if ($paginator->onFirstPage())<span class="pager-link disabled" aria-disabled="true">‹ Previous</span>@else<a class="pager-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">‹ Previous</a>@endif
            @foreach ($elements as $element)
                @if (is_string($element))<span class="pager-link disabled">{{ $element }}</span>@endif
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())<span class="pager-link current" aria-current="page">{{ $page }}</span>@else<a class="pager-link" href="{{ $url }}">{{ $page }}</a>@endif
                    @endforeach
                @endif
            @endforeach
            @if ($paginator->hasMorePages())<a class="pager-link" href="{{ $paginator->nextPageUrl() }}" rel="next">Next ›</a>@else<span class="pager-link disabled" aria-disabled="true">Next ›</span>@endif
        </span>
    </nav>
@endif
