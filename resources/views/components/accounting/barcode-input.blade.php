@props([
    'name' => 'barcode',
    'id' => null,
    'value' => '',
    'label' => null,
    'placeholder' => null,
    'autofocus' => false,
    'autocomplete' => 'off',
    'required' => false,
    'showStatus' => false,
    'status' => 'unknown',
    'help' => null,
])

@php
    $id = $id ?: 'ac-barcode-' . $name;
    $label = $label ?? __('accounting.barcode.field_label');
    $placeholder = $placeholder ?? __('accounting.barcode.field_placeholder');
@endphp

{{-- حقل إدخال باركود.
     ⚠️ مهمّ للوصولية: قارئات الباركود USB تُعرّف نفسها للجهاز كـ**لوحة مفاتيح**،
     تكتب الرقم ثم ترسل Enter. لذلك:
       1) يجب أن يكون <label for> حقيقيًا — ليعرف قارئ الشاشة ما هو الحقل.
       2) Enter يجب أن يعمل بلا ماوس (يُعالجه accounting-barcode.js).
       3) `inputmode="numeric"` + `autocomplete="off"` يمنعان إكمالًا تلقائيًا يكسر الرقم.
       4) `dir="ltr"` لأن الباركود لاتيني/رقمي ويُقرأ من اليسار. --}}
<div class="ac-field {{ $showStatus ? 'has-status' : '' }}">
    <label for="{{ $id }}" class="ac-field-label">
        {{ $label }}
        @if($required)<span class="ac-req" aria-hidden="true">*</span>@endif
    </label>

    <div class="ac-barcode-wrap">
        <span class="ac-barcode-icon" aria-hidden="true">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 5v14M7 5v14M11 5v14M15 5v10M19 5v14"></path>
            </svg>
        </span>

        <input type="text"
               id="{{ $id }}"
               name="{{ $name }}"
               value="{{ $value }}"
               class="ac-barcode-input"
               dir="ltr"
               inputmode="numeric"
               autocomplete="{{ $autocomplete }}"
               spellcheck="false"
               placeholder="{{ $placeholder }}"
               data-barcode-input
               @if($autofocus) autofocus @endif
               @if($required) required aria-required="true" @endif
               {{ $attributes }}>

        {{-- مؤشّر الحالة يُملأ من JS بعد البحث --}}
        @if($showStatus)
            <span class="ac-barcode-state" data-barcode-state data-barcode-status="{{ $status }}" aria-live="polite"></span>
        @endif
    </div>

    @if($help)
        <p class="ac-field-help">{{ $help }}</p>
    @endif

    <p class="ac-field-help ac-barcode-hint" data-barcode-help>
        @lang('accounting.barcode.not_every_medicine_hint')
    </p>
</div>
