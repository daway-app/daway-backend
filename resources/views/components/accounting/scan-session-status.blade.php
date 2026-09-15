@props([
    'status' => 'waiting',
])

@php
    // الحالات الثمانية المطلوبة — كل واحدة بنقطة ولون دلالي من tokens.css
    $statuses = [
        'waiting' => ['class' => 'is-waiting', 'key' => 'scanner.state_waiting'],
        'connecting' => ['class' => 'is-connecting', 'key' => 'scanner.state_connecting'],
        'connected' => ['class' => 'is-connected', 'key' => 'scanner.state_connected'],
        'scanning' => ['class' => 'is-scanning', 'key' => 'scanner.state_scanning'],
        'received' => ['class' => 'is-received', 'key' => 'scanner.state_received'],
        'disconnected' => ['class' => 'is-disconnected', 'key' => 'scanner.state_disconnected'],
        'expired' => ['class' => 'is-expired', 'key' => 'scanner.state_expired'],
        'error' => ['class' => 'is-error', 'key' => 'scanner.state_error'],
    ];

    $meta = $statuses[$status] ?? $statuses['waiting'];
@endphp

{{-- مؤشّر حالة جلسة المسح.
     ⚠️ `aria-live="polite"` إلزامي: الصيدلي مشغول بالسلة، والتغيير يأتي
     من جهاز آخر — يجب أن يُعلَن بلا أن يسرق التركيز من حقل الإدخال. --}}
<div class="ac-session-status {{ $meta['class'] }}"
     data-session-status
     data-status="{{ $status }}"
     role="status"
     aria-live="polite">
    <span class="ac-session-dot" aria-hidden="true"></span>
    <span class="ac-session-label" data-session-label>
        @lang("accounting.{$meta['key']}")
    </span>
    <span class="ac-session-timer" data-session-timer hidden aria-hidden="true"></span>
</div>
