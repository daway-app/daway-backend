<?php

// C-1/H-3: البروكسي الموثوق — يقرؤه middleware الـ TrustProxies وقت الطلب عبر
// config('trustedproxy.proxies')، فلا يُنفَّذ أي env() في مرحلة التسجيل المبكرة
// ويعمل بشكل صحيح بعد php artisan config:cache.
// قيمة فارغة = لا نثق بأي وسيط → REMOTE_ADDR هو عنوان العميل (سلوك Render الصحيح).
// لا تضف '*' أبداً — يجعل $request->ip() قابلاً للتزييف عبر X-Forwarded-For (H-2).
return [
    'proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),
];
