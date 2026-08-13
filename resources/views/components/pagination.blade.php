@if ($paginator->hasPages())
    <div class="pager">
        {{-- Previous Page Link --}}
        @if ($paginator->onFirstPage())
            <button class="pg-btn" disabled>«</button>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" class="pg-btn">«</a>
        @endif

        {{-- Pagination Elements --}}
        @foreach ($elements as $element)
            @if (is_string($element))
                <span style="font-size:.75rem;color:var(--gray-400)">{{ $element }}</span>
            @endif

            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <button class="pg-btn on">{{ $page }}</button>
                    @else
                        <a href="{{ $url }}" class="pg-btn">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        {{-- Next Page Link --}}
        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" class="pg-btn">»</a>
        @else
            <button class="pg-btn" disabled>»</button>
        @endif
    </div>
@endif
