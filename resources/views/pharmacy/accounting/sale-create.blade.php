@extends('layouts.app')

@section('title', __('accounting.pos.title'))

@section('breadcrumb')
    <span aria-hidden="true">/</span>
    <a href="{{ route('pharmacy.accounting.overview') }}">@lang('accounting.nav.breadcrumb_accounting')</a>
    <span aria-hidden="true">/</span>
    <a href="{{ route('pharmacy.accounting.sales.index') }}">@lang('accounting.sidebar.sales')</a>
    <span aria-hidden="true">/</span>
    <span aria-current="page">@lang('accounting.sales.new_sale')</span>
@endsection

@section('content')
    @vite([
        'resources/css/pages/pharmacy_hub.css',
        'resources/css/pages/pharmacy_accounting.css',
        'resources/js/accounting/accounting-shared.js',
        'resources/js/accounting/accounting-barcode.js',
        'resources/js/accounting/accounting-scanner-session.js',
        'resources/js/accounting/accounting-phone-scanner.js',
        'resources/js/accounting/accounting-pos.js',
    ])
    @include('partials.accounting-i18n')

    @php
        // ⚠️ `medicine_id` إلزامي في الـpayload: الـbarcode endpoint يعيد
        // `local_medicine_id` = `medicines.id`، فبدونه لا يمكن ربط نتيجة
        // المسح بصف مخزون ⇒ `in_inventory` يصير false والبيع يُمنع ظلمًا.
        $catalogPayload = collect($posCatalog)->map(fn ($m) => [
            'id' => $m['id'],
            'medicine_id' => $m['medicine_id'] ?? null,
            'barcode' => $m['barcode'],
            'trade_name' => $m['trade_name'],
            'active_ingredient' => $m['active_ingredient'],
            'price' => $m['price'],
            'quantity' => $m['quantity'],
        ])->values()->all();

        // ⚠️ لا نضع `endpoints` هنا: `Object.assign` سطحية فتُستبدل كتلة
        // الـendpoints القادمة من `partials.accounting-i18n` وتضيع الـ19 مسارًا.
        // (هذا ما كان يحدث فعلًا ⇒ حفظ الفاتورة يرجع not_configured بلا شبكة.)
        // مسارا البحث يُدمجان أدناه في نفس كائن الـendpoints.
        $searchEndpoint = url('/api/medicines/search');
        $barcodeEndpoint = url('/api/medicines/barcode');

        $posConfig = [
            'currency' => \App\Support\Accounting\AccountingMockData::CURRENCY,
            'demo' => true,
            'catalog' => $catalogPayload,
        ];
    @endphp

    <div class="ph-page">
        @if($isDemo)
            <div class="ac-demo-banner" role="status">
                <i class="fas fa-flask" aria-hidden="true"></i>
                <span>@lang('accounting.common.mock_notice')</span>
            </div>
        @endif

        <div class="ph-head">
            <div class="ph-page-title">
                <h1>@lang('accounting.pos.heading')</h1>
                <p>@lang('accounting.pos.subtitle')</p>
            </div>
            <div class="ph-actions">
                <a href="{{ route('pharmacy.accounting.sales.index') }}" class="ph-btn ghost">
                    <i class="fas fa-arrow-right" aria-hidden="true"></i> @lang('accounting.sidebar.sales')
                </a>
            </div>
        </div>

        <div class="ac-pos">
            {{-- ================= العمود الأيسر: الإدخال + السلة ================= --}}
            <div class="ac-pos-left">
                {{-- ============================================================
                     مسار الإدخال المزدوج: البحث والمسح **متساويان**
                     ============================================================
                     ⚠️ المبدأ: قاعدة `moh_medicines` لا تحتوي باركودًا كاملًا،
                     والتغطية تُبنى تدريجيًا من الصيدليات. لذلك المسح ليس
                     «طريقًا احتياطيًّا» — بل مسار أول بنفس وزن البحث بالاسم.

                     الترتيب البصري: حقل الباركود أولًا لأن الاستخدام اليومي
                     للصيدلية الحقيقية يبدأ بالمسح، ثم البحث بالاسم للبدائل.
                     ============================================================ --}}
                <section class="ph-card ac-entry-card" aria-labelledby="ac-entry-heading">
                    <div class="ph-card-head">
                        <h2 id="ac-entry-heading">
                            <i class="fas fa-barcode" aria-hidden="true"></i>
                            @lang('accounting.pos.entry_title')
                        </h2>
                        <p>@lang('accounting.pos.entry_subtitle')</p>
                    </div>

                    <div class="ph-card-body">
                        {{-- --- المسار 1: الباركود / المسح --- --}}
                        <x-accounting.barcode-input
                            name="barcode"
                            id="ac-barcode"
                            :autofocus="true"
                            :show-status="true"
                            help="{{ __('accounting.pos.barcode_hint') }}" />

                        <div class="ac-scan-actions">
                            <x-accounting.barcode-scan-button
                                target="ac-barcode"
                                :label="__('accounting.barcode.scan_button')" />

                            {{-- 📱 طريقة الإدخال الثالثة — بنفس وزن المسح المحلي.
                                 الهاتف يشغّل الكاميرا؛ الويب يستقبل نصًّا فقط. --}}
                            <x-accounting.phone-scanner-button />

                            <span class="ac-scan-or" aria-hidden="true">
                                @lang('accounting.pos.or')
                            </span>

                            <button type="button" class="ph-btn ghost sm" data-ac-focus-search>
                                <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
                                @lang('accounting.pos.search_by_name')
                            </button>
                        </div>

                        {{-- بطاقة نتيجة المسح — تظهر فقط عند التعرّف على الدواء --}}
                        <div class="ac-scan-found" data-ac-scan-found hidden>
                            <span class="ac-scan-found-body">
                                <span class="ac-scan-found-name" data-ac-found-name dir="auto"></span>
                                <span class="ac-scan-found-meta" data-ac-found-meta></span>
                            </span>
                            <x-accounting.barcode-status-badge status="pending" :show-hint="false" size="sm" data-ac-found-badge />
                            <button type="button" class="ph-btn primary sm" data-ac-found-add>
                                <i class="fas fa-plus" aria-hidden="true"></i>
                                @lang('accounting.barcode.found_add')
                            </button>
                        </div>

                        {{-- حالة «غير مسجَّل» — رسالة بناء تغطية لا خطأ --}}
                        <div class="ac-note is-info ac-hidden" data-ac-scan-unknown role="status">
                            <span class="ac-note-icon" aria-hidden="true">i</span>
                            <div>
                                <strong>@lang('accounting.barcode.link_note_title')</strong>
                                <p>@lang('accounting.barcode.link_note_body')</p>
                            </div>
                        </div>

                        {{-- --- المسار 2: البحث بالاسم --- --}}
                        <div class="ac-field">
                            <label for="ac-search" class="ac-field-label">
                                @lang('accounting.pos.search_label')
                            </label>
                            <div class="ac-search-wrap">
                                <span class="ac-search-icon" aria-hidden="true">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="11" cy="11" r="8"></circle>
                                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                    </svg>
                                </span>
                                <input type="search"
                                       id="ac-search"
                                       class="ac-search-input"
                                       data-ac-search
                                       autocomplete="off"
                                       placeholder="@lang('accounting.pos.search_placeholder')">
                            </div>
                            <p class="ac-field-help">@lang('accounting.pos.search_hint')</p>
                        </div>

                        <div class="ac-inline-msg" data-ac-search-msg aria-live="polite"></div>
                        <ul class="ac-search-results" data-ac-results role="listbox"
                            aria-label="@lang('accounting.pos.search_results')"></ul>
                    </div>
                </section>

                {{-- السلة --}}
                <section class="ph-card" aria-labelledby="ac-cart-heading" style="margin-block-end:0;">
                    <div class="ph-card-head">
                        <h2 id="ac-cart-heading">
                            <i class="fas fa-cart-shopping" aria-hidden="true"></i>
                            @lang('accounting.pos.cart_title')
                            <span class="ph-badge new" style="margin-inline-start:6px;">
                                <span data-ac-cart-count>0</span>
                            </span>
                        </h2>
                        <p>@lang('accounting.pos.cart_items')</p>
                    </div>

                    {{-- حالة فارغة --}}
                    <div class="ph-empty" data-ac-cart-empty>
                        <i class="fas fa-basket-shopping" aria-hidden="true"></i>
                        <h3>@lang('accounting.pos.cart_empty')</h3>
                        <p>@lang('accounting.pos.cart_empty_desc')</p>
                    </div>

                    {{-- الجدول --}}
                    <div class="ac-cart-wrap ac-hidden" data-ac-cart-table>
                        <table class="ac-cart-table">
                            <caption class="ac-hidden">@lang('accounting.pos.cart_title')</caption>
                            <thead>
                                <tr>
                                    <th scope="col">@lang('accounting.common.medicine')</th>
                                    <th scope="col">@lang('accounting.barcode.inventory_column')</th>
                                    <th scope="col">@lang('accounting.common.qty')</th>
                                    <th scope="col">@lang('accounting.common.unit_price')</th>
                                    <th scope="col">@lang('accounting.common.discount')</th>
                                    <th scope="col">@lang('accounting.common.total')</th>
                                    <th scope="col"><span class="ac-hidden">@lang('accounting.common.remove')</span></th>
                                </tr>
                            </thead>
                            <tbody data-ac-cart-body></tbody>
                        </table>
                    </div>

                    <div class="ph-card-body ac-hidden" data-ac-cart-toolbar>
                        <div class="ac-cart-toolbar">
                            <button type="button" class="ph-btn danger sm" data-ac-clear>
                                <i class="fas fa-trash-can" aria-hidden="true"></i> @lang('accounting.pos.clear_cart')
                            </button>
                        </div>
                    </div>
                </section>
            </div>

            {{-- ================= العمود الأيمن: الفاتورة ================= --}}
            <div class="ac-pos-right">
                <div class="ac-invoice-panel">
                    <div class="ac-invoice-head">
                        <h2><i class="fas fa-file-invoice" aria-hidden="true"></i> @lang('accounting.pos.invoice_title')</h2>
                    </div>

                    <div class="ac-invoice-body">
                        {{-- رقم الفاتورة --}}
                        <div class="ph-group">
                            <label>@lang('accounting.pos.invoice_number')</label>
                            <input type="text" class="ph-control" value="" disabled
                                   placeholder="@lang('accounting.pos.invoice_number_pending')">
                        </div>

                        {{-- العميل --}}
                        <div class="ph-group">
                            <label for="ac-customer">@lang('accounting.pos.customer_label')</label>
                            <input type="text" id="ac-customer" class="ph-control" data-ac-customer
                                   autocomplete="off"
                                   placeholder="@lang('accounting.pos.customer_search_placeholder')">
                            {{-- نتائج بحث العملاء الحقيقيين — كل زر يحمل customer_id --}}
                            <div class="ac-search-results ac-customer-matches"
                                 data-ac-customer-matches hidden></div>
                            <span class="ph-hint">@lang('accounting.pos.customer_walk_in')</span>
                        </div>

                        {{-- الإجماليات --}}
                        <div class="ac-invoice-line">
                            <span class="label">@lang('accounting.pos.subtotal')</span>
                            <span class="value"><span data-ac-subtotal>0.00</span> {{ \App\Support\Accounting\AccountingMockData::CURRENCY }}</span>
                        </div>
                        <div class="ac-invoice-line">
                            <span class="label">@lang('accounting.pos.discount')</span>
                            <span class="value ac-neg">−<span data-ac-discount>0.00</span> {{ \App\Support\Accounting\AccountingMockData::CURRENCY }}</span>
                        </div>
                        <div class="ac-invoice-line">
                            <span class="label">
                                @lang('accounting.pos.tax')
                                <i class="fas fa-circle-info" style="font-size:.75rem;" aria-hidden="true"
                                   title="@lang('accounting.pos.tax_not_supported')"></i>
                            </span>
                            <span class="value ac-muted">@lang('accounting.pos.tax_not_supported')</span>
                        </div>

                        <div class="ac-invoice-line is-total">
                            <span class="label ac-strong">@lang('accounting.pos.total')</span>
                            <span class="value" data-ac-total>0.00 {{ \App\Support\Accounting\AccountingMockData::CURRENCY }}</span>
                        </div>

                        {{-- المدفوع --}}
                        <div class="ph-group">
                            <label for="ac-paid">@lang('accounting.pos.paid')</label>
                            <input type="number" id="ac-paid" class="ph-control" data-ac-paid
                                   min="0" step="0.01" inputmode="decimal" placeholder="0.00">
                        </div>

                        <div class="ac-invoice-line is-remaining" data-ac-remaining-line>
                            <span class="label">@lang('accounting.pos.remaining')</span>
                            <span class="value"><span data-ac-remaining>0.00</span> {{ \App\Support\Accounting\AccountingMockData::CURRENCY }}</span>
                        </div>

                        {{-- طريقة الدفع --}}
                        <div class="ph-group">
                            <label for="ac-payment">@lang('accounting.pos.payment_method')</label>
                            <select id="ac-payment" class="ph-select" data-ac-payment>
                                @foreach($paymentMethods as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="ac-inline-msg" data-ac-msg aria-live="assertive"></div>
                    </div>

                    <div class="ac-invoice-foot">
                        <button type="button" class="ph-btn primary" data-ac-submit>
                            <i class="fas fa-circle-check" aria-hidden="true"></i>
                            <span data-ac-submit-label>@lang('accounting.pos.complete_sale')</span>
                        </button>
                        <p class="ac-invoice-note">
                            @lang('accounting.common.mock_notice')
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ============================================================
         حوارات الباركود — تُبنى مرة واحدة وتُستخدم من أي صفحة.
         لا يوجد endpoint ربط ⇒ النافذة تعرض حالة «غير متاح» بصراحة
         بدل زر يوهم بالحفظ ثم يفشل.
         ============================================================ --}}
    <x-accounting.barcode-link-modal :endpoint="null" />
    <x-accounting.barcode-conflict-modal />
    <x-accounting.phone-scanner-modal />

    @php
        // ⚠️ مسارات جلسة المسح **غير موجودة** في الباك-إند.
        //
        // 🔴 لا تمرّر `AccountingMockData::scanSessionEndpoints()` هنا: تُعيد
        // `[]`، وهي **truthy في JS** ⇒ `scanner-session.start()` يعتقد أن
        // الوضع 'live' ثم يفشل أول نداء ويعرض **حالة خطأ** للمستخدم. القيمة
        // الصحيحة هي `null` صريحًا ليُصرّح الموديول بـ`unavailable` بهدوء.
        // (والوضع التجريبي أدناه هو ما يُستعمل فعليًّا إلى أن يوجد الباك-إند.)
        $scanSessionEndpoints = null;

        $scannerConfig = [
            // نقل الباركود: polling محدود (2000ms) — لا WebSocket (غير موجود
            // في المشروع أصلًا) ولا polling سريع.
            'transport' => 'polling',
            // الوضع التجريبي يُعلَن صراحةً في الواجهة عبر وسم «وضع تجريبي»
            'scanSessionMock' => $scanSessionEndpoints === null,
            'scanSessionMockBarcodes' => \App\Support\Accounting\AccountingMockData::scanSessionDemoBarcodes(),
        ];

        $posConfig = array_merge($posConfig, [
            'scanSessionTransport' => $scannerConfig['transport'],
            'scanSessionMock' => $scannerConfig['scanSessionMock'],
            'scanSessionMockBarcodes' => $scannerConfig['scanSessionMockBarcodes'],
        ]);

        // ⚠️ `scanSessions` **لا** يوجد داخل `$scannerConfig` عن قصد: المفتاح
        // يُحقن في `accounting-i18n` كـ`null` (انظر التعليق هناك)، ولا نُعيد
        // كتابته من هنا حتى لا نُدخل `[]` (truthy في JS ⇒ حالة خطأ).
        // أسماء مسارات مسح الهاتف — مفقودة الآن لأن الـBackend غير موجود بعد.

        // عنوان صفحة الفاتورة — يُبنى هنا بـroute() لأن JS لا يبني مسارات.
        $invoiceUrlTemplate = route('pharmacy.accounting.sales.show', ['number' => '__NUMBER__']);
    @endphp

    <script>
        // ⚠️ `Object.assign` سطحية (shallow): لو مرّرنا `endpoints` داخلها
        // **يُستبدل الكائن كاملًا** ونفقد الـ19 مسارًا القادمة من
        // `partials.accounting-i18n` — وعندها `salesCreate` تصير undefined
        // فيتعطّل الحفظ كليًّا بلا أي طلب شبكي. لذا ندمج طبقةً بطبقة.
        window.acAccountingConfig = Object.assign(
            {},
            window.acAccountingConfig || {},
            @json($posConfig)
        );

        // ⚠️ لا نلمس `endpoints.scanSessions` هنا إطلاقًا.
        // قيمته `null` (من `accounting-i18n`) هي **الإشارة المقصودة** إلى أن
        // الباك-إند غير موجود — والموديول يحوّلها إلى `unavailable` بهدوء.
        // تمرير مصفوفة فارغة هنا كان يجعل `start()` يظنّ الوضع 'live' ثم يعرض
        // **حالة خطأ** للمستخدم (لأن `[]` truthy في JS).
        // `Object.assign` تتجاهل المصادر `null` anyway، فالحذف لا يغيّر السلوك
        // — لكنه يمنع عودة الخطأ سهوًا عند أي تعديل لاحق.

        // إعدادات شاشة البيع (عنوان الفاتورة) + مسارا البحث — في نفس كائن
        // الـendpoints الآتي من الـpartial، فلا يضيع أي مسار محاسبي.
        window.acPosConfig = {
            invoiceUrlTemplate: @json($invoiceUrlTemplate),
            searchUrl: @json($searchEndpoint),
            barcodeUrl: @json($barcodeEndpoint)
        };
        window.acAccountingConfig.endpoints.medicineSearch = @json($searchEndpoint);
        window.acAccountingConfig.endpoints.barcodeLookup = @json($barcodeEndpoint);
    </script>
@endsection
