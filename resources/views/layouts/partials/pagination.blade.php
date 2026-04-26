@if ($paginator->hasPages())
    <div class="pagination-bar">
        <span class="pagination-summary">
            Showing {{ $paginator->firstItem() ?? 0 }} to {{ $paginator->lastItem() ?? 0 }} of {{ $paginator->total() }} records
        </span>

        <div class="pagination-links">
            @if ($paginator->onFirstPage())
                <span class="button button-disabled">Previous</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" class="button button-secondary">Previous</a>
            @endif

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" class="button button-secondary">Next</a>
            @else
                <span class="button button-disabled">Next</span>
            @endif
        </div>
    </div>
@endif
