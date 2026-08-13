<div class="mo" id="{{ $id }}" onclick="if(event.target===this)this.classList.remove('open')">
    <div class="mo-box">
        <div class="mo-head">
            <span style="font-size:20px">{{ $icon }}</span>
            <h3>{{ $title }}</h3>
            <button class="mo-close" onclick="document.getElementById('{{ $id }}').classList.remove('open')">✕</button>
        </div>
        <div class="mo-body">
            {{ $slot }}
        </div>
        @if(isset($footer))
            <div class="mo-foot">
                {{ $footer }}
            </div>
        @endif
    </div>
</div>
