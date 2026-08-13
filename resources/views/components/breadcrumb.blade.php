<div class="bc">
    <a href="{{ route('dashboard') }}" class="home">🏠 الرئيسية</a>
    @if(isset($items))
        @foreach($items as $item)
            <span class="sep">›</span>
            @if(isset($item['url']) && !$loop->last)
                <a href="{{ $item['url'] }}">{{ $item['title'] }}</a>
            @else
                <span class="cur">{{ $item['title'] }}</span>
            @endif
        @endforeach
    @endif
</div>
