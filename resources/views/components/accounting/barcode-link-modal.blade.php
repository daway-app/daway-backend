@props([
    'id' => 'ac-barcode-link-modal',
    'barcode' => null,
    'title' => null,
    'endpoint' => null,
])

@php
    $title = $title ?? __('accounting.barcode.link_modal_title');
    // ⚠️ لا يوجد `POST /api/barcodes/link` في الباك-إند. الزر يُعطَّل ويُشرح السبب
    // بدل أن يبدو فعّالًا ثم يفشل. عند توفّر الـendpoint: مرّر $endpoint وسيعمل.
    $hasEndpoint = ! empty($endpoint);
@endphp

{{-- نافذة «ربط الباركود بدواء موجود».
     هذه هي الحالة التي تُغطّي **أغلب** عمليات المسح اليوم: الباركود غير موجود في
     القاعدة بعد. التصميم يعاملها كخطوة بناء تغطية عادية، **لا كخطأ**.

     البنية مقصودة لتكون قابلة للاستخدام بلا JS للقراءة (تفاصيل مفهومة)، لكن الربط
     نفسه يتطلب الباك-إند. --}}
<div class="ph-modal-overlay ac-modal-overlay"
     id="{{ $id }}"
     data-barcode-link-modal
     data-endpoint="{{ $endpoint }}"
     role="dialog"
     aria-modal="true"
     aria-labelledby="{{ $id }}-title">

    <div class="ph-modal ac-modal is-wide">
        <div class="ph-modal-head">
            <div>
                <h3 id="{{ $id }}-title">{{ $title }}</h3>
                <p class="ac-modal-sub" data-link-sub>
                    @lang('accounting.barcode.link_modal_sub')
                </p>
            </div>

            <button type="button" class="ph-btn icon ghost" data-modal-close data-barcode-link-cancel
                    aria-label="{{ __('accounting.common.close') }}">
                <i class="fas fa-times" aria-hidden="true"></i>
            </button>
        </div>

        <div class="ph-modal-body">
            {{-- الباركود الممسوح — ثابت وبارز، ليتأكد الصيدلي أنه يربط الرقم الصحيح --}}
            <div class="ac-linked-barcode">
                <span class="ac-linked-barcode-label">@lang('accounting.barcode.scanned_code')</span>
                <code class="ac-linked-barcode-code" dir="ltr" data-link-barcode>{{ $barcode ?: '—' }}</code>
            </div>

            {{-- تنبيه محايد: يشرح لماذا لم نجد الباركود، بلا لغة فشل --}}
            <div class="ac-note is-info" role="status">
                <span class="ac-note-icon" aria-hidden="true">i</span>
                <div>
                    <strong>@lang('accounting.barcode.link_note_title')</strong>
                    <p>@lang('accounting.barcode.link_note_body')</p>
                </div>
            </div>

            {{-- البحث عن الدواء الموجود --}}
            <div class="ac-field">
                <label for="{{ $id }}-search" class="ac-field-label">
                    @lang('accounting.barcode.link_search_label')
                </label>
                <div class="ac-search-wrap">
                    <span class="ac-search-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"></circle>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                        </svg>
                    </span>
                    <input type="search"
                           id="{{ $id }}-search"
                           class="ac-search-input"
                           data-link-search
                           autocomplete="off"
                           placeholder="{{ __('accounting.barcode.link_search_placeholder') }}">
                </div>
                <p class="ac-field-help">@lang('accounting.barcode.link_search_help')</p>
            </div>

            {{-- نتائج البحث --}}
            <div class="ac-link-results" data-link-results aria-live="polite">
                <div class="ac-link-placeholder">
                    @lang('accounting.barcode.link_search_empty')
                </div>
            </div>

            {{-- الدواء المختار --}}
            <div class="ac-link-chosen" data-link-chosen hidden>
                <span class="ac-link-chosen-label">@lang('accounting.barcode.link_chosen')</span>
                <span class="ac-link-chosen-name" data-link-chosen-name dir="auto"></span>
                <button type="button" class="ph-btn xs ghost" data-link-clear>
                    @lang('accounting.barcode.link_change')
                </button>
            </div>

            {{-- حالة انعدام الـendpoint: صريحة بلا تجميل --}}
            @unless($hasEndpoint)
                <div class="ac-note is-warn" role="note">
                    <span class="ac-note-icon" aria-hidden="true">!</span>
                    <div>
                        <strong>@lang('accounting.barcode.link_unavailable_title')</strong>
                        <p>@lang('accounting.barcode.link_unavailable_body')</p>
                    </div>
                </div>
            @endunless
        </div>

        <div class="ph-modal-foot">
            <button type="button" class="ph-btn ghost" data-barcode-link-cancel>
                @lang('accounting.common.cancel')
            </button>

            <button type="button" class="ph-btn primary"
                    data-barcode-link-save
                    @unless($hasEndpoint) disabled aria-disabled="true"
                    title="{{ __('accounting.barcode.link_unavailable_title') }}" @endunless>
                {{ __('accounting.barcode.link_save') }}
            </button>
        </div>
    </div>
</div>
