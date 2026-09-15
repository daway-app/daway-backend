@props([
    'mock' => false,
])

@php
    // ⚠️ الحالة التجريبية تُعلَن صراحةً في الواجهة. لا نوهم أحدًا بأن جلسة
    // اقتران حقيقية قائمة. تُفعَّل فقط في التطوير، وتُطفأ تلقائيًا عند
    // توفّر مسارات الباك-إند.
    $hasEndpoints = ! empty(\App\Support\Accounting\AccountingMockData::scanSessionEndpoints());
@endphp

{{-- نافذة المسح بالهاتف — المركز الذي يدير جلسة الاقتران.

     ⚠️ الويب هنا:
        - يُنشئ جلسة مسح مؤقتة
        - يعرض QR/رمزًا قصيرًا
        - يعرض حالة الاتصال
        - **يستقبل نصّ الباركود** ويضيفه للسلة
     ولا يشغّل الكاميرا ولا يستقبل صورًا إطلاقًا. --}}
<div class="ph-modal-overlay ac-modal-overlay"
     id="ac-phone-scanner-modal"
     data-phone-scanner-modal
     role="dialog"
     aria-modal="true"
     aria-labelledby="ac-phone-scanner-title">

    <div class="ph-modal ac-modal ac-phone-modal">
        <div class="ph-modal-head">
            <div>
                <h3 id="ac-phone-scanner-title">
                    <span class="ac-phone-modal-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="6" y="2" width="12" height="20" rx="2"></rect>
                            <line x1="10" y1="18" x2="14" y2="18"></line>
                            <path d="M9 8h1M11 8h1M13 8h1M9 11h1M11 11h1M13 11h1"></path>
                        </svg>
                    </span>
                    @lang('accounting.scanner.modal_title')
                </h3>
                <p class="ac-modal-sub">@lang('accounting.scanner.modal_sub')</p>
            </div>

            <button type="button" class="ph-btn icon ghost" data-modal-close data-phone-scanner-close
                    aria-label="{{ __('accounting.common.close') }}">
                <i class="fas fa-times" aria-hidden="true"></i>
            </button>
        </div>

        <div class="ph-modal-body">
            {{-- وسم الوضع التجريبي — صريح ولا يُخفى --}}
            @unless($hasEndpoints)
                <div class="ac-note is-warn" role="note" data-scanner-mock-notice>
                    <span class="ac-note-icon" aria-hidden="true">!</span>
                    <div>
                        <strong>@lang('accounting.scanner.mock_title')</strong>
                        <p>@lang('accounting.scanner.mock_body')</p>
                    </div>
                </div>
            @endunless

            {{-- الخطوات — مختصرة وواضحة --}}
            <ol class="ac-pair-steps">
                <li>@lang('accounting.scanner.step_1')</li>
                <li>@lang('accounting.scanner.step_2')</li>
                <li>@lang('accounting.scanner.step_3')</li>
            </ol>

            {{-- الحالة — أول ما تقع عليه العين بعد الإقران --}}
            <x-accounting.scan-session-status status="waiting" />

            {{-- الباركود الوارد — يُملأ من JS --}}
            <div class="ac-received" data-session-received hidden>
                <span class="ac-received-label">@lang('accounting.scanner.received_label')</span>
                <output class="ac-received-code" data-received-code dir="ltr"></output>
                <span class="ac-received-hint" data-received-hint></span>
            </div>

            {{-- الطابور — يظهر فقط عند وجود أكثر من باركود منتظر --}}
            <div class="ac-queue" data-session-queue hidden>
                <span class="ac-queue-label">@lang('accounting.scanner.queue_label')</span>
                <ul class="ac-queue-list" data-queue-list></ul>
            </div>

            {{-- بطاقة الاقتران (QR + الرمز) --}}
            <div data-pair-section>
                <x-accounting.pairing-qr-card :pairing-code="null" :qr-svg="null" :qr-url="null" />
            </div>

            {{-- الأجهزة المرتبطة --}}
            <div class="ac-phone-divider" aria-hidden="true"></div>
            <x-accounting.connected-device-card :active="null" />

            {{-- توضيح تقني مهم — يمنع توقعات خاطئة --}}
            <p class="ac-phone-foot-note">
                @lang('accounting.scanner.no_camera_note')
            </p>
        </div>

        <div class="ph-modal-foot">
            {{-- أزرار الحالة: تتبدّل حسب الحالة الفعلية --}}
            <button type="button" class="ph-btn ghost" data-scanner-reconnect hidden>
                <i class="fas fa-rotate-right" aria-hidden="true"></i>
                @lang('accounting.scanner.reconnect')
            </button>

            <button type="button" class="ph-btn outline" data-scanner-new-session hidden>
                <i class="fas fa-plus" aria-hidden="true"></i>
                @lang('accounting.scanner.new_session')
            </button>

            {{-- زر عرض تجريبي فقط — لا يظهر في الإنتاج --}}
            @unless($hasEndpoints)
                <button type="button" class="ph-btn ghost" data-scanner-simulate>
                    <i class="fas fa-flask" aria-hidden="true"></i>
                    @lang('accounting.scanner.simulate')
                </button>
            @endunless

            <button type="button" class="ph-btn outline" data-phone-scanner-close>
                @lang('accounting.scanner.close_scanner')
            </button>
        </div>
    </div>
</div>
