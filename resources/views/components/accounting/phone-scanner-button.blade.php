@props([
    'size' => null,
])

{{-- زر «المسح بالهاتف» — طريقة الإدخال الثالثة، بنفس وزن المسح والبحث.

     ⚠️ المبدأ: الهاتف **طريقة إدخال إضافية**، لا نظام نقطة بيع منفصل.
     كل الطرق تنتهي إلى نفس المسار:
         lookup الدواء  →  السلة  →  البيع
     فلا تُنشئ مسارًا موازيًا ولا منطق سلة ثانيًا.

     ⚠️ الكاميرا **لا** تُشغَّل هنا — الهاتف هو من يصوّر ويُفكّ الترميز،
     والويب يستقبل **نصّ الباركود فقط**. --}}
<button type="button"
        class="ac-phone-scan-btn {{ $size === 'lg' ? 'is-lg' : '' }}"
        data-phone-scanner-open
        aria-haspopup="dialog"
        aria-controls="ac-phone-scanner-modal"
        {{ $attributes }}>
    <span class="ac-phone-scan-icon" aria-hidden="true">
        <svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <rect x="6" y="2" width="12" height="20" rx="2"></rect>
            <line x1="10" y1="18" x2="14" y2="18"></line>
            <path d="M9 8h1M11 8h1M13 8h1M9 11h1M11 11h1M13 11h1"></path>
        </svg>
    </span>
    <span class="ac-phone-scan-text">@lang('accounting.scanner.button')</span>
    {{-- شارة «جهاز متصل» — تظهر فقط عندما يكون هناك هاتف مقترن --}}
    <span class="ac-phone-scan-dot" data-phone-scanner-dot hidden aria-hidden="true"></span>
</button>
