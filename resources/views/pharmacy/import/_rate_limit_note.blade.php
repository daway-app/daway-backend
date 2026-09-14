{{--
    مؤشّر الحصة المتبقية من عمليات الاستيراد (HTTP 429 وقايةً لا علاجاً).

    يُعرض فقط بعد أول عملية فعلية: قبلها لا معنى لتحذير عن حصة لم تُلمس،
    وإظهاره دائماً يخلق ضجيجاً بصرياً في كل فتح صفحة.

    القيمة تأتي من InventoryImportThrottle::status() — نفس المفتاح الذي
    يفرضه الخادم بالضبط، فلا يفترق ما نعرضه عمّا يحدث فعلاً.
--}}
@php
    $rateLimit = $rateLimit ?? null;
@endphp

@if (is_array($rateLimit) && ($rateLimit['exhausted'] ?? false))
    <p class='pi-inline-note pi-rate-limit is-exhausted' data-pi-rate-limit='exhausted'>
        {{ __('pharmacy_import.rate_limit_exhausted', ['minutes' => max(1, (int) ceil(((int) ($rateLimit['reset_in'] ?? 0)) / 60))]) }}
    </p>
@elseif (is_array($rateLimit) && ((int) ($rateLimit['used'] ?? 0)) > 0)
    <p class='pi-inline-note pi-rate-limit' data-pi-rate-limit='remaining'>
        {{ __('pharmacy_import.rate_limit_remaining', ['count' => (int) $rateLimit['remaining'], 'limit' => (int) $rateLimit['limit']]) }}
    </p>
@endif
