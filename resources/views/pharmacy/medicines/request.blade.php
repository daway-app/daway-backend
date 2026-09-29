@extends('layouts.app')

@section('title', __('pharmacy.medicines.request.title'))

@section('content')
    {{-- ⚠️ لماذا تُحمَّل ملفات المحاسبة هنا:
         صفحة «طلب دواء جديد» تحتاج **مسح الباركود**، ومحرّك الباركود في المشروع
         واحد فقط: `resources/js/accounting/*`. لا نكتب ماسحًا ثانيًا (قرار مسبق:
         «لا نظام نقطة بيع موازٍ»)، بل نُعيد استخدام نفس المكوّنات ونمرّر سلوكًا
         مخصّصًا لهذه الصفحة عبر `window.__acScanHandlers` (انظر قسم scripts أدناه).
         الترتيب إلزامي: shared → barcode → session → phone-scanner. --}}
    @vite([
        'resources/css/pages/medicines_edit.css',
        'resources/css/pages/pharmacy_hub.css',
        'resources/css/pages/pharmacy_accounting.css',
        'resources/js/accounting/accounting-shared.js',
        'resources/js/accounting/accounting-barcode.js',
        'resources/js/accounting/accounting-scanner-session.js',
        'resources/js/accounting/accounting-phone-scanner.js',
    ])
    @include('partials.accounting-i18n')

    <div class="edit-medicine-page-wrapper">
        <div class="page-heading">
            <div>
                <h1>@lang('pharmacy.medicines.request.heading', ['pharmacy' => $pharmacy->pharmacy_name])</h1>
                <p>@lang('pharmacy.medicines.request.subtitle')</p>
            </div>
        </div>

        <form action="{{ route('pharmacy.medicines.request.store') }}" method="POST" class="premium-card">
            @csrf
            <div class="card-body">

                <div class="form-row">
                    <div class="fg">
                        <label class="fl" for="trade_name">@lang('pharmacy.medicines.request.trade_name') <span class="req">*</span></label>
                        <input class="fc" type="text" id="trade_name" name="trade_name" dir="ltr" value="{{ old('trade_name') }}" required>
                        @error('trade_name')<span class="error-text" role="alert">{{ $message }}</span>@enderror
                    </div>
                    <div class="fg">
                        <label class="fl" for="trade_name_ar">@lang('pharmacy.medicines.request.trade_name_ar')</label>
                        <input class="fc" type="text" id="trade_name_ar" name="trade_name_ar" value="{{ old('trade_name_ar') }}">
                        @error('trade_name_ar')<span class="error-text" role="alert">{{ $message }}</span>@enderror
                    </div>
                </div>

                <div class="form-row">
                    <div class="fg">
                        <label class="fl" for="active_ingredient">@lang('pharmacy.medicines.request.active_ingredient')</label>
                        <input class="fc" type="text" id="active_ingredient" name="active_ingredient" dir="ltr" value="{{ old('active_ingredient') }}">
                        @error('active_ingredient')<span class="error-text" role="alert">{{ $message }}</span>@enderror
                    </div>
                    <div class="fg">
                        <label class="fl" for="category_id">@lang('pharmacy.medicines.request.category') <span class="req">*</span></label>
                        <select class="fc" id="category_id" name="category_id" required>
                            <option value="">—</option>
                            @foreach($categories as $cat)
                                <option value="{{ $cat->id }}" {{ old('category_id') == $cat->id ? 'selected' : '' }}>{{ $cat->name_ar }}</option>
                            @endforeach
                        </select>
                        @error('category_id')<span class="error-text" role="alert">{{ $message }}</span>@enderror
                    </div>
                </div>

                {{-- الباركود — اختياري، لكنه أهمّ ما يوفّر وقت الإدارة عند الاعتماد.
                     يُملأ بثلاث طرق: كتابة يدوية · قارئ USB (Enter) · المسح بالهاتف. --}}
                <div class="form-row">
                    <div class="fg" style="grid-column: 1 / -1;">
                        <x-accounting.barcode-input
                            name="barcode"
                            id="request_barcode"
                            :value="old('barcode', '')"
                            :label="__('pharmacy.medicines.request.barcode')"
                            :show-status="true" />

                        <div class="ac-scan-actions">
                            <x-accounting.barcode-scan-button target="request_barcode" />
                            <x-accounting.phone-scanner-button />
                        </div>

                        @error('barcode')<span class="error-text" role="alert">{{ $message }}</span>@enderror
                    </div>
                </div>

            </div>
            <div class="card-foot">
                <button type="submit" class="btn-submit">@lang('pharmacy.medicines.request.submit')</button>
                <a href="{{ route('pharmacy.medicines.index') }}" class="btn-cancel">@lang('pharmacy.medicines.request.cancel')</a>
            </div>
        </form>

        {{-- نافذة المسح بالهاتف — نفس المكوّن المستخدم في المحاسبة بلا أي تغيير.
             `accounting-phone-scanner.js` تخرج مبكرًا إن لم تجد `[data-phone-scanner-modal]`. --}}
        <x-accounting.phone-scanner-modal />

        {{-- نافذة تعارض الباركود — الباركود فريد عالميًا في `medicine_barcodes`.
             لو مُسح باركود مرتبط بدواء آخر، نعرض الحوار بدل تجاهل صامت. --}}
        <x-accounting.barcode-conflict-modal />
    </div>
@endsection

@section('scripts')
<script>
/*
 * جسر هذه الصفحة مع محرّك الباركود المشترك.
 *
 * ⚠️ لماذا `__acScanHandlers` وليس `__acPosHook`:
 *   `__acPosHook.addScannedResult` مسار **السلة** في نقطة البيع. هذه الصفحة
 *   لا سلة فيها؛ غايتها تعبئة حقل الباركود فقط. لو استُخدم مسار السلة لظهرت
 *   رسالة «أُضيف للسلة» — وهي كاذبة هنا.
 *
 * ⚠️ التوقيت: هذا سكربت كلاسيكي inline، يُنفَّذ أثناء تحليل الصفحة، بينما
 *   ملفات Vite تُحمَّل كـ`type="module"` (deferred). لذا يُضبط المتغيّر
 *   **قبل** تنفيذ `init()` في accounting-barcode.js — بلا سباق.
 */
(function () {
    'use strict';

    var BARCODE_INPUT_ID = 'request_barcode';

    /** يكتب الباركود في الحقل ويُحدّث مؤشّر الحالة — بلا أي منطق إضافي. */
    function fillBarcode(result) {
        var input = document.getElementById(BARCODE_INPUT_ID);
        if (!input) {
            return;
        }

        var code = (result && result.barcode) ? String(result.barcode) : '';
        if (!code) {
            return;
        }

        input.value = code;

        var wrap = input.closest('.ac-barcode-wrap');
        if (wrap && window.AccountingBarcode && window.AccountingBarcode.setFieldState) {
            // الحالة 'pending' لأن الـendpoint لم يُثبِت توثيق الرقم بعد.
            window.AccountingBarcode.setFieldState(wrap, 'pending', code);
        }

        if (window.AccountingBarcode && window.AccountingBarcode.notify) {
            window.AccountingBarcode.notify(@json(__('pharmacy.medicines.request.barcode_filled')), 'success');
        }
    }

    window.__acScanHandlers = {
        // باركود غير مسجَّل — الحالة الطبيعية هنا (الدواء غير موجود بالكتالوج أصلًا).
        // بدون هذا المعالج تُفتح نافذة «ربط بدواء موجود»، وهي بلا معنى في صفحة الطلب.
        onUnknown: fillBarcode,
        // باركود مسجَّل — نكتفي بتعبئة الحقل بدل إشعار «أُضيف للسلة» الكاذب.
        onResolved: fillBarcode
    };
})();
</script>
@endsection
