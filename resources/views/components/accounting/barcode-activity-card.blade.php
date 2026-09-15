@props([
    'activities' => [],
    'title' => null,
    'subtitle' => null,
    'limit' => 6,
])

@php
    $title = $title ?? __('accounting.barcode.activity_title');
    $subtitle = $subtitle ?? __('accounting.barcode.activity_subtitle');
    $rows = array_slice((array) $activities, 0, $limit);
@endphp

{{-- بطاقة «آخر نشاط الباركود».
     تُحمَّل مع الصفحة فقط — ⚠️ **لا polling، ولا مؤقّت تجديد**. القرار مقصود:
     مسح الباركود فعل موضعي داخل نقطة البيع، وشبكة غزة ليست مكانًا نستهلك فيه
     دورات الشبكة على قائمة لا يقرأها أحد لحظيًّا.

     عند توفّر endpoint حقيقي: مرّر $activities من الكنترولر. الحقول المتوقّعة:
       ['barcode' => string, 'medicine' => string, 'status' => unknown|pending|verified,
        'source' => ?string, 'at' => string (نصّ جاهز للعرض)] --}}
<div class="ph-card ac-activity-card">
    <div class="ph-card-head">
        <h2>
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M3 5v14M7 5v14M11 5v14M15 5v10M19 5v14"></path>
            </svg>
            {{ $title }}
        </h2>
        <p>{{ $subtitle }}</p>
    </div>

    <div class="ph-card-body ac-activity-body">
        @if(empty($rows))
            {{-- حالة فراغ مفيدة: تشرح النمو الطبيعي للتغطية بلا نبرة نقص --}}
            <div class="ac-empty-inline">
                <span class="ac-empty-icon" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 5v14M7 5v14M11 5v14M15 5v10M19 5v14"></path>
                    </svg>
                </span>
                <p class="ac-empty-title">@lang('accounting.barcode.activity_empty_title')</p>
                <p class="ac-empty-desc">@lang('accounting.barcode.activity_empty_desc')</p>
            </div>
        @else
            <ul class="ac-activity-list">
                @foreach($rows as $row)
                    <li class="ac-activity-row">
                        <span class="ac-activity-code" dir="ltr">{{ $row['barcode'] ?? '—' }}</span>
                        <span class="ac-activity-name" dir="auto">{{ $row['medicine'] ?? '—' }}</span>
                        <x-accounting.barcode-status-badge
                            :status="$row['status'] ?? 'unknown'"
                            :show-hint="false"
                            size="sm" />
                        <span class="ac-activity-time">{{ $row['at'] ?? '' }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
