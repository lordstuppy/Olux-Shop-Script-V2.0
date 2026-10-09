@if ($paginator->hasPages())
    <nav aria-label="{{ __('Pagination') }}">
        <ul class="pagination">
            @if ($paginator->onFirstPage())
                <li><span aria-disabled="true">{{ __('Previous') }}</span></li>
            @else
                <li><a href="{{ $paginator->previousPageUrl() }}" rel="prev">{{ __('Previous') }}</a></li>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <li><span aria-disabled="true">{{ $element }}</span></li>
                @endif
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <li aria-current="page"><span>{{ $page }}</span></li>
                        @else
                            <li><a href="{{ $url }}" aria-label="{{ __('Page :page', ['page' => $page]) }}">{{ $page }}</a></li>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <li><a href="{{ $paginator->nextPageUrl() }}" rel="next">{{ __('Next') }}</a></li>
            @else
                <li><span aria-disabled="true">{{ __('Next') }}</span></li>
            @endif
        </ul>
    </nav>
@endif
