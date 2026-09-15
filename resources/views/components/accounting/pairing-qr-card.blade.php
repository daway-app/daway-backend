@props([
    'pairingCode' => null,
    'qrSvg' => null,
    'qrUrl' => null,
])

{{-- بطاقة الاقتران: QR + رمز قصير.

     ⚠️ قواعد أمنية صارمة في هذا المكوّن:
       - الـQR يمثّل **جلسة اقتران مؤقتة فقط** (`pairing_url`).
       - **يُمنع** ترميز كلمة مرور، أو API key، أو personal access token،
         أو أي مصادقة دائمة داخل الـQR. عند بناء الحمولة في الباك-إند:
         ضع معرّف جلسة عشوائيًّا قصير العمر لا أكثر.
       - لا نعرض معرّفات داخلية غير ضرورية للمستخدم.

     ⚠️ لا توجد مكتبة QR في المشروع (لا composer ولا package.json ولا
     node_modules). لذلك هذا المكوّن يقبل SVG/URL جاهزًا من الباك-إند،
     ويسقط إلى **بطاقة الرمز القصير** وحدها إن لم يتوفّر — بصراحة ودون
     تظاهر بعرض QR غير موجود. --}}
<div class="ac-pair-card">
    <div class="ac-pair-qr">
        @if($qrSvg)
            {{-- SVG جاهز من الباك-إند — يُدرج كما هو (مصدره موثوق، خادمنا) --}}
            <div class="ac-pair-qr-render" data-pair-qr-render role="img"
                 aria-label="@lang('accounting.scanner.qr_alt')">
                {!! $qrSvg !!}
            </div>
            <p class="ac-pair-qr-cap">@lang('accounting.scanner.qr_caption')</p>
        @elseif($qrUrl)
            {{-- صورة QR من مسار يوفّره الباك-إند --}}
            <img src="{{ $qrUrl }}"
                 class="ac-pair-qr-render"
                 alt="@lang('accounting.scanner.qr_alt')"
                 width="168" height="168">
            <p class="ac-pair-qr-cap">@lang('accounting.scanner.qr_caption')</p>
        @else
            {{-- مؤقّت صريح: لا نوهم المستخدم بوجود QR --}}
            <div class="ac-pair-qr-placeholder" data-pair-qr-placeholder role="img"
                 aria-label="@lang('accounting.scanner.qr_pending_alt')">
                <span class="ac-pair-qr-grid" aria-hidden="true">
                    @for($i = 0; $i < 64; $i++)
                        <span class="ac-pair-qr-cell {{ in_array($i % 9, [0, 2, 3, 6, 7], true) ? 'is-on' : '' }}"></span>
                    @endfor
                </span>
            </div>
            <p class="ac-pair-qr-cap is-pending">
                @lang('accounting.scanner.qr_pending')
            </p>
        @endif
    </div>

    <div class="ac-pair-code-box">
        <span class="ac-pair-code-label">@lang('accounting.scanner.pairing_code')</span>
        <output class="ac-pair-code" data-pair-code dir="ltr">{{ $pairingCode ?: '—' }}</output>
        <p class="ac-pair-code-hint">@lang('accounting.scanner.pairing_code_hint')</p>
    </div>
</div>
