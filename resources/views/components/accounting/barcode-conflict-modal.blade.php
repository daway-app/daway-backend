@props([
    'id' => 'ac-barcode-conflict-modal',
    'barcode' => null,
    'linkedName' => null,
    'existingName' => null,
])

@php
    $title = __('accounting.barcode.conflict_title');
@endphp

{{-- نافذة «تعارض باركود».
     تُعرض حين يكون الباركود مرتبطًا بدواء **آخر** في القاعدة.

     ⚠️ قواعد ثابتة في هذا الملف:
       1) **لا نتجاوز قواعد الباك-إند.** `medicine_barcodes.barcode` فريد عالميًا،
          و`uniq_medicine_barcodes_barcode` هو من يمنع الازدواج — لا نتخطاه بـJS.
       2) **الرسالة غير تقنية.** الصيدلي لا يعرف ولا يجب أن يعرف شيئًا عن
          unique indexes. نقول: «هذا الرقم مربوط بدواء آخر».
       3) **خياران فقط:** مراجعة (تفتح سياق القرار) أو إلغاء. لا زر «تجاوز».
       4) **لا نعيد تسمية «التعارض» ليكون «ربطًا جديدًا»** — هذا يسجّل بيانات خاطئة
          بهدوء، وهو أسوأ من رفض العملية. --}}
<div class="ph-modal-overlay ac-modal-overlay"
     id="{{ $id }}"
     data-barcode-conflict-modal
     role="alertdialog"
     aria-modal="true"
     aria-labelledby="{{ $id }}-title"
     aria-describedby="{{ $id }}-desc">

    <div class="ph-modal ac-modal">
        <div class="ph-modal-head is-conflict">
            <div>
                <h3 id="{{ $id }}-title">
                    <span class="ac-modal-title-icon is-conflict" aria-hidden="true">!</span>
                    {{ $title }}
                </h3>
            </div>

            <button type="button" class="ph-btn icon ghost" data-modal-close data-conflict-cancel
                    aria-label="{{ __('accounting.common.close') }}">
                <i class="fas fa-times" aria-hidden="true"></i>
            </button>
        </div>

        <div class="ph-modal-body">
            {{-- الشرح بلغة بشرية: ما الذي حدث، وماذا يعني عمليًّا --}}
            <p class="ac-conflict-text" id="{{ $id }}-desc">
                @lang('accounting.barcode.conflict_desc')
            </p>

            {{-- مقارنة: الرقم الممسوح ↔ الدواء المربوط به حاليًا --}}
            <div class="ac-conflict-compare">
                <div class="ac-conflict-row">
                    <span class="ac-conflict-key">@lang('accounting.barcode.scanned_code')</span>
                    <code class="ac-conflict-val" dir="ltr" data-conflict-barcode>{{ $barcode ?: '—' }}</code>
                </div>

                <div class="ac-conflict-row is-existing">
                    <span class="ac-conflict-key">@lang('accounting.barcode.conflict_linked_to')</span>
                    <span class="ac-conflict-val is-name" dir="auto" data-conflict-existing>
                        {{ $existingName ?: '—' }}
                    </span>
                </div>

                @if($linkedName)
                    <div class="ac-conflict-row is-attempt">
                        <span class="ac-conflict-key">@lang('accounting.barcode.conflict_you_tried')</span>
                        <span class="ac-conflict-val is-name" dir="auto">{{ $linkedName }}</span>
                    </div>
                @endif
            </div>

            <div class="ac-note is-warn" role="note">
                <span class="ac-note-icon" aria-hidden="true">!</span>
                <div>
                    <strong>@lang('accounting.barcode.conflict_note_title')</strong>
                    <p>@lang('accounting.barcode.conflict_note_body')</p>
                </div>
            </div>
        </div>

        <div class="ph-modal-foot">
            <button type="button" class="ph-btn ghost" data-conflict-cancel>
                @lang('accounting.common.cancel')
            </button>

            {{-- «مراجعة» تقود إلى سياق الحلّ (تبويب المراجعات/طلب تغيير).
                 لا تحلّ التعارض تلقائيًا ولا تُنشئ ارتباطًا ثانيًا. --}}
            <button type="button" class="ph-btn outline" data-conflict-review>
                @lang('accounting.barcode.conflict_review')
            </button>
        </div>
    </div>
</div>
