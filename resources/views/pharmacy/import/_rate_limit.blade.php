{{--
    مساعد موحّد لطلبات الاستيراد: يقرأ ترويسة Retry-After ويعالج HTTP 429.

    لماذا في الواجهة وليس في الخادم فقط؟
    لأن الـ commit/cancel نماذج ويب عادية (تنقّل كامل للصفحة) فيتكفّل الخادم
    بها؛ أما decide وبحث الكتالوج فطلبات fetch، وهناك فقط يمكن إعادة المحاولة
    تلقائياً بلا أن يفقد الصيدلي قراراته المكتوبة في الجدول.

    السلوك:
      - 429 وانتظار قصير (≤ AUTO_RETRY_MAX_SECONDS) → انتظار ثم إعادة محاولة واحدة.
      - 429 وانتظار طويل → لا تجميد للواجهة، بل رسالة عربية واضحة بالثواني.
      - إعادة المحاولة آمنة لأن 429 تعني أن الطلب رُفض قبل أن يصل للـ controller.

    يُضمَّن في صفحة المراجعة (fetch) — وليس في صفحة الرفع (نموذج عادي).
--}}
@php
    // ⚠️ نبني المصفوفة في PHP ثم نمرّرها كمتغيّر واحد: توجيه @json يقسم
    // التعبير على أول فاصلة ويأخذ الجزء الأول فقط، فـ
    // @json(__('key', ['seconds' => '…'])) يُقصّ بصمت ويُنتج PHP غير صالح.
    $rateLimitMessages = [
        'tooMany' => __('pharmacy_import.error_too_many_requests', ['seconds' => '__SECONDS__']),
        'tooManyGeneric' => __('pharmacy_import.error_too_many_requests_generic'),
        'retrying' => __('pharmacy_import.rate_limit_retrying', ['seconds' => '__SECONDS__']),
    ];
@endphp
<script>
    window.DawayRateLimit = (function () {
        var TEMPLATES = @json($rateLimitMessages);

        // لا نُجمّد الواجهة أطول من ذلك — ما زاد عليه نُبلغ الصيدلي ونتوقف.
        var AUTO_RETRY_MAX_SECONDS = 15;

        function fill(template, seconds) {
            return String(template).split('__SECONDS__').join(seconds);
        }

        /** ثواني الانتظار من Retry-After، وباحتياط من X-RateLimit-Reset. */
        function parseRetryAfter(response) {
            var raw = response.headers.get('Retry-After');

            if (raw !== null) {
                var direct = parseInt(raw, 10);
                if (!isNaN(direct) && direct >= 0) { return direct; }
            }

            var reset = response.headers.get('X-RateLimit-Reset');

            if (reset !== null) {
                var at = parseInt(reset, 10);
                if (!isNaN(at)) {
                    var diff = at - Math.floor(Date.now() / 1000);
                    if (diff > 0) { return diff; }
                }
            }

            return 0;
        }

        function message(seconds) {
            return seconds > 0 ? fill(TEMPLATES.tooMany, seconds) : TEMPLATES.tooManyGeneric;
        }

        function sleep(ms) {
            return new Promise(function (resolve) { window.setTimeout(resolve, ms); });
        }

        /**
         * طلب JSON يتحمّل 429.
         * يعيد: { ok, status, body, retryAfter, message }
         */
        function request(url, options, autoRetry) {
            options = options || {};
            autoRetry = autoRetry !== false;

            return fetch(url, options).then(function (response) {
                if (response.status !== 429) {
                    return response.json()
                        .catch(function () { return {}; })
                        .then(function (body) {
                            return {
                                ok: response.ok,
                                status: response.status,
                                body: body,
                                retryAfter: 0,
                                message: ''
                            };
                        });
                }

                var seconds = parseRetryAfter(response);

                if (autoRetry && seconds > 0 && seconds <= AUTO_RETRY_MAX_SECONDS) {
                    return sleep((seconds + 1) * 1000).then(function () {
                        return request(url, options, false);
                    });
                }

                return response.json()
                    .catch(function () { return {}; })
                    .then(function (body) {
                        return {
                            ok: false,
                            status: 429,
                            retryAfter: seconds,
                            body: body,
                            message: (body && body.message) || message(seconds)
                        };
                    });
            });
        }

        return {
            request: request,
            message: message,
            parseRetryAfter: parseRetryAfter,
            autoRetryMaxSeconds: AUTO_RETRY_MAX_SECONDS
        };
    })();
</script>
