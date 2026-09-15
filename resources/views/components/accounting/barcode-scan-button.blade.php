@props([
    'target' => null,      // id حقل الباركود الذي يخدمه الزر
    'label' => null,
    'size' => null,        // null | 'lg'
    'variant' => 'outline', // outline | solid
    'disabled' => false,
    'reason' => null,       // سبب التعطيل (يُعرض كـtitle)
])

@php
    $label = $label ?? __('accounting.barcode.scan_button');
    $classes = 'ac-scan-btn is-' . $variant;

    if ($size === 'lg') {
        $classes .= ' is-lg';
    }
@endphp

{{-- زر المسح.
     المسح و البحث **مساران متساويان** لا أساس وبديل: هذا الزر يحمل نفس الوزن
     البصري لحقل البحث النصّي في نقطة البيع.

     ⚠️ الكاميرا غير منفَّذة في هذه المرحلة (قرار مقصود). الزر الآن:
       - يُركّز حقل الباركود فيعمل مع قارئ USB مباشرة، و
       - يفتح نافذة إدخال يدوي إن كان الجهاز بلا قارئ.
     عند إضافة قارئ الفلاتر لاحقًا: هذا الزر هو نقطة الوصل الوحيدة — لا تعدّل غيره. --}}
<button type="button"
        class="{{ $classes }}"
        data-barcode-scan
        @if($target) data-scan-target="{{ $target }}" @endif
        @if($disabled) disabled aria-disabled="true" @if($reason) title="{{ $reason }}" @endif @endif
        {{ $attributes }}>
    <span class="ac-scan-icon" aria-hidden="true">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 7V5a2 2 0 0 1 2-2h2"></path>
            <path d="M17 3h2a2 2 0 0 1 2 2v2"></path>
            <path d="M21 17v2a2 2 0 0 1-2 2h-2"></path>
            <path d="M7 21H5a2 2 0 0 1-2-2v-2"></path>
            <line x1="3" y1="12" x2="21" y2="12"></line>
        </svg>
    </span>
    <span class="ac-scan-text">{{ $label }}</span>
</button>
