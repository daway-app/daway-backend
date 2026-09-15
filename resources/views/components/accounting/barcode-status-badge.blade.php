@props([
    'status' => 'unknown',   // unknown | pending | verified | conflict
    'count' => null,         // عدد الباركودات (لصفحات الدواء)
    'showHint' => true,      // الشرح القصير كـtitle
    'size' => null,          // null | 'sm'
])

@php
    use App\Support\Accounting\BarcodeStatus;

    $status = BarcodeStatus::normalize($status);
    $class = 'ac-bcode-badge ' . BarcodeStatus::cssClass($status);
    $label = BarcodeStatus::label($status);
    $hint = BarcodeStatus::hint($status);

    // ⚠️ قاعدة مقصودة: «غير معروف» **ليست** حالة خطأ.
    // نضبط aria-label بوصف محايد حتى لا يقرأ قارئ الشاشة نبرة فشل في مسار طبيعي.
    $ariaLabel = $label;
    if ($status === BarcodeStatus::UNKNOWN) {
        $ariaLabel = $label . ' — ' . $hint;
    }
@endphp

{{-- شارة حالة الباركود — المكوّن الوحيد الذي يُعرض حالة الباركود في كل الصفحات.

     تُستخدم في: نقطة البيع · مخزون الصيدلية · صفحة الدواء · بطاقة التغطية.
     لا تكرّر markup الشارة في أي قالب — استخدم هذا المكوّن. --}}
<span class="{{ $class }} {{ $size === 'sm' ? 'is-sm' : '' }}"
      data-barcode-badge
      data-barcode-status="{{ $status }}"
      role="status"
      aria-label="{{ $ariaLabel }}"
      @if($showHint) title="{{ $hint }}" @endif>

    <span class="ac-bcode-dot" aria-hidden="true"></span>
    <span class="ac-bcode-label">{{ $label }}</span>

    {{-- عدد الباركودات المرتبطة — الدواء الواحد قد يحمل أكثر من باركود --}}
    @if(! is_null($count) && $count > 0)
        <span class="ac-bcode-count" aria-label="{{ trans_choice('accounting.barcode.codes_count', $count, ['count' => $count]) }}">
            {{ $count }}
        </span>
    @endif
</span>
