@props([
    'active' => null,
])

{{-- قائمة أجهزة المسح المرتبطة.

     ⚠️ جهاز واحد فقط هو **الماسح النشط** في كل لحظة — الـPOS يحدّده بوضوح
     حتى لا يقع الصيدلي في حيرة «أي هاتف مسح؟». بقية الأجهزة تُعرض معطّلة
     بصريًّا (بلا وظيفة إرسال) حتى لو سُمح لها لاحقًا.

     ⚠️ لا معرّفات داخلية ولا توكنات — **اسم الجهاز والحالة فقط**. --}}
<div class="ac-devices" data-devices-card>
    <div class="ac-devices-head">
        <span class="ac-devices-title">@lang('accounting.scanner.devices_title')</span>
    </div>

    {{-- جهاز واحد على الأقل --}}
    <ul class="ac-devices-list" data-devices-list>
        @if($active)
            <li class="ac-device-row is-active" data-device-row>
                <span class="ac-device-icon" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="6" y="2" width="12" height="20" rx="2"></rect>
                        <line x1="10" y1="18" x2="14" y2="18"></line>
                    </svg>
                </span>
                <span class="ac-device-name" data-device-name dir="auto">{{ $active }}</span>
                <span class="ac-device-badge">@lang('accounting.scanner.device_active')</span>
                <button type="button" class="ph-btn xs ghost" data-device-disconnect>
                    @lang('accounting.scanner.disconnect')
                </button>
            </li>
        @else
            <li class="ac-device-empty" data-device-empty>
                @lang('accounting.scanner.devices_empty')
            </li>
        @endif
    </ul>

    {{-- طلب اقتران جهاز آخر — قرار بشري، لا تجاوز تلقائي --}}
    <div class="ac-device-request" data-device-request hidden role="alertdialog"
         aria-labelledby="ac-device-req-title">
        <p class="ac-device-request-title" id="ac-device-req-title">
            @lang('accounting.scanner.device_request_title')
        </p>
        <p class="ac-device-request-name" data-device-request-name dir="auto"></p>
        <div class="ac-device-request-actions">
            <button type="button" class="ph-btn primary sm" data-device-allow>
                @lang('accounting.scanner.device_allow')
            </button>
            <button type="button" class="ph-btn ghost sm" data-device-reject>
                @lang('accounting.scanner.device_reject')
            </button>
        </div>
    </div>
</div>
