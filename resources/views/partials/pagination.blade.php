{{-- ترقيم صفحات موحّد لكل الصفحات (أدمن + صيدلية):
     نفس الclasses المطلوبة من ستايل .pagination-wrapper الموجود
     في app.css + صفحات الأدمن، فتصير أزرار التنقل شكلها متناسق بكل مكان. --}}
@if ($paginator->hasPages())
    <div class="pagination-wrapper">
        <nav role="navigation" aria-label="Pagination Navigation" class="pagination-nav">
            <ul>
                {{-- السابق --}}
                @if ($paginator->onFirstPage())
                    <li class="disabled"><span aria-disabled="true">‹</span></li>
                @else
                    <li><a href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label=".previous">@lang('pagination.previous')</a></li>
                @endif

                {{-- الأرقام + الشُرط --}}
                @foreach ($elements as $element)
                    @if (is_string($element))
                        <li class="disabled"><span>{{ $element }}</span></li>
                    @endif
                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            @if ($page == $paginator->currentPage())
                                <li class="active"><span aria-current="page">{{ $page }}</span></li>
                            @else
                                <li><a href="{{ $url }}">{{ $page }}</a></li>
                            @endif
                        @endforeach
                    @endif
                @endforeach

                {{-- التالي --}}
                @if ($paginator->hasMorePages())
                    <li><a href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="next">@lang('pagination.next')</a></li>
                @else
                    <li class="disabled"><span aria-disabled="true">›</span></li>
                @endif
            </ul>
        </nav>
    </div>
@endif
