<div class="sc {{ $type }}">
    <div class="sc-ico">{{ $icon }}</div>
    <div class="sc-val">{{ $value }}</div>
    <div class="sc-lbl">{{ $label }}</div>
    @if($trend)
        <div class="sc-trend {{ $trendUp ? 'up' : 'dn' }}">
            {{ $trendUp ? '▲' : '▼' }} {{ $trend }}
        </div>
    @endif
</div>
