@extends('layouts.app')

@section('title', __('pharmacy.alternatives.index.title'))

@section('content')
    @vite(['resources/css/pages/pharmacy_hub.css', 'resources/js/pharmacy_hub.js'])
    @include('partials.pharmacy-hub-i18n')

    @php
        $needsAlternative = $pharmacyMedicines->filter(fn($pm) => $pm->quantity <= 0 && $pm->medicine->alternatives->isEmpty());
        $confirmDelete = __('pharmacy.alternatives.index.confirm_delete');
    @endphp

    <div class='ph-page'>
        <div class='ph-head'>
            <div class='ph-page-title'>
                <h1>@lang('pharmacy.alternatives.index.heading_page')</h1>
                <p>@lang('pharmacy.alternatives.index.subtitle')</p>
            </div>
            <div class='ph-actions'>
                <a href='{{ route('pharmacy.alternatives.create') }}' class='ph-btn primary'><i class='fas fa-plus'></i> @lang('pharmacy.alternatives.index.add_button')</a>
            </div>
        </div>

        @if (session('success'))
            <div class='ph-card' style='margin-block-end:20px;background:var(--ph-green-bg);color:var(--ph-green);border-color:var(--ph-green-bg);padding:14px 18px;'>{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class='ph-card' style='margin-block-end:20px;background:var(--ph-red-bg);color:var(--ph-red);border-color:var(--ph-red-bg);padding:14px 18px;'>{{ session('error') }}</div>
        @endif

        <div class='ph-stats'>
            <div class='ph-stat'><i class='fas fa-arrows-rotate teal'></i><div><strong>{{ $totalAlternatives }}</strong><span>@lang('pharmacy.alternatives.index.stat_defined')</span></div></div>
            <div class='ph-stat'><i class='fas fa-triangle-exclamation orange'></i><div><strong>{{ $needsAlternative->count() }}</strong><span>@lang('pharmacy.alternatives.index.stat_need')</span></div></div>
        </div>

        <div class='ph-filters'>
            <div class='ph-search' style='min-width:320px;'>
                <i class='fas fa-search'></i>
                <input type='text' placeholder='@lang('pharmacy.alternatives.index.search_placeholder')' data-ph-search='.ph-alt-block'>
            </div>
        </div>

        @forelse($pharmacyMedicines as $pm)
            @php
                $isOut = $pm->quantity <= 0;
                $currentAlts = $pm->medicine->alternatives;
                // C2.1: لا تحمّل Medicine::all() — استعلم لكل دواء حسب المادة الفعالة
                $candidates = \App\Models\Medicine::where('id', '!=', $pm->medicine_id)
                    ->where('active_ingredient', '=', $pm->medicine->active_ingredient)
                    // C2.2: self-pair protection — إذا المادة الفعالة فارغة، لا يُعادل أدويةً عشوائية
                    ->when(empty($pm->medicine->active_ingredient), fn ($q) => $q->whereRaw('0 = 1'))
                    ->orderBy('trade_name')
                    ->get(['id', 'trade_name', 'active_ingredient']);
                // Badge حالة: بديل في مخزون الصيدلية ومتوفر الآن؟
                $stockByCandidate = \App\Models\PharmacyMedicine::query()
                    ->where('pharmacy_id', $pharmacy->id)
                    ->whereIn('medicine_id', $candidates->pluck('id'))
                    ->get(['medicine_id', 'quantity', 'is_available'])
                    ->keyBy('medicine_id');
            @endphp
            <div class='ph-card ph-alt-block' style='margin-block-end:14px;'>
                <div class='ph-card-head' role='button' tabindex='0' data-ph-toggle='alt-body-{{ $pm->id }}' aria-expanded='true' aria-controls='alt-body-{{ $pm->id }}' style='cursor:pointer;user-select:none;'>
                    <h2 style='display:flex;align-items:center;gap:10px;'>
                        <i class='fas fa-pills'></i> {{ $pm->medicine->trade_name }}
                        @if($isOut)
                            <span class='ph-badge out' style='font-size:.7rem;'>@lang('pharmacy.alternatives.index.badge_unavailable')</span>
                        @endif
                        <span class='ph-alt-count' style='font-size:.7rem;color:var(--ph-ink-faint);font-weight:400;'>({{ $currentAlts->count() }})</span>
                    </h2>
                    <i class='fas fa-chevron-down ph-toggle-icon' style='transition:transform .2s ease;'></i>
                </div>
                <div class='ph-card-body' id='alt-body-{{ $pm->id }}' style='display:grid;grid-template-columns:280px 1fr;gap:22px;'>
                    <div>
                        <div class='ph-alt-detail'>
                            <div class='row'><span>@lang('pharmacy.alternatives.index.detail_ingredient')</span><strong>{{ $pm->medicine->active_ingredient ?: '—' }}</strong></div>
                            <div class='row'><span>@lang('pharmacy.alternatives.index.detail_quantity')</span><strong style='color:{{ $isOut ? 'var(--ph-red)' : 'var(--ph-ink)' }}'>{{ $pm->quantity }}</strong></div>
                            <div class='row'><span>@lang('pharmacy.alternatives.index.detail_updated')</span><strong>{{ $pm->updated_at->format('Y-m-d h:i A') }}</strong></div>
                        </div>
                        @if($currentAlts->isEmpty())
                            <div class='ph-alt-notice'><i class='fas fa-circle-info'></i> @lang('pharmacy.alternatives.index.no_alternative_notice')</div>
                        @endif
                    </div>

                    <div class='ph-table-wrap'>
                        <table class='ph-table'>
                            <thead><tr><th>@lang('pharmacy.alternatives.index.col_medicine')</th><th>@lang('pharmacy.alternatives.index.col_ingredient')</th><th>@lang('pharmacy.alternatives.index.col_actions')</th></tr></thead>
                            <tbody>
                                @forelse($candidates as $cand)
                                    @php
                                        $selected = $currentAlts->contains('id', $cand->id);
                                        $stock = $stockByCandidate->get($cand->id);
                                        $inStock = $stock && (int) $stock->quantity > 0 && (bool) $stock->is_available;
                                    @endphp
                                    <tr @if($selected) style="background:rgba(22,163,74,.06);" @endif>
                                        <td><strong>{{ $cand->trade_name }}</strong></td>
                                        <td>{{ $cand->active_ingredient }}</td>
                                        <td>
                                            @if($inStock)
                                                <span class='ph-badge ok' style='font-size:.72rem;'>@lang('pharmacy.alternatives.index.badge_in_stock')</span>
                                            @else
                                                <span class='ph-badge low' style='font-size:.72rem;'>@lang('pharmacy.alternatives.index.badge_not_in_stock')</span>
                                            @endif
                                            @if($selected)
                                                <button type='button' class='ph-btn sm' style='background:var(--ph-red);color:#fff;border-color:var(--ph-red);margin-inline-start:8px;' data-ph-confirm-delete data-url='{{ route('pharmacy.alternatives.destroy', ['pharmacyMedicine' => $pm->id, 'alternative' => $cand->id]) }}' data-confirm='{{ $confirmDelete }}'><i class='fas fa-trash'></i> @lang('pharmacy.alternatives.index.delete_tooltip')</button>
                                                <form id='ph-delete-form-{{ $pm->id }}-{{ $cand->id }}' action='{{ route('pharmacy.alternatives.destroy', ['pharmacyMedicine' => $pm->id, 'alternative' => $cand->id]) }}' method='POST' style='display:none;'>
                                                    @csrf
                                                    @method('DELETE')
                                                </form>
                                            @else
                                                <form action='{{ route('pharmacy.alternatives.store') }}' method='POST' style='display:inline;'>
                                                    @csrf
                                                    <input type='hidden' name='base_medicine_id' value='{{ $pm->id }}'>
                                                    <input type='hidden' name='alternative_medicine_id' value='{{ $cand->id }}'>
                                                    <button type='submit' class='ph-btn sm outline'>@lang('pharmacy.alternatives.index.choose_alternative')</button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan='3'><div class='ph-empty' style='padding:24px;'><i class='fas fa-box-open'></i><h3>@lang('pharmacy.alternatives.index.no_candidates')</h3></div></td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @empty
            <div class='ph-empty'><i class='fas fa-box-open'></i><h3>@lang('pharmacy.alternatives.index.empty_medicines')</h3></div>
        @endforelse
    </div>

    {{-- Modal تأكيد الحذف --}}
    <div class='ph-modal-overlay' id='phDeleteConfirmModal' role='dialog' aria-modal='true' aria-labelledby='phDeleteConfirmTitle'>
        <div class='ph-modal' style='max-width:420px;'>
            <div class='ph-modal-head'>
                <h3 id='phDeleteConfirmTitle'>@lang('pharmacy.alternatives.index.confirm_delete_title')</h3>
                <button type='button' class='ph-close' data-ph-confirm-cancel>&times;</button>
            </div>
            <div class='ph-modal-body'>
                <p id='phDeleteConfirmMessage' style='margin:0;font-size:.9rem;color:var(--ph-ink-soft);line-height:1.8;'></p>
            </div>
            <div class='ph-modal-foot'>
                <button type='button' class='ph-btn ghost' data-ph-confirm-cancel>@lang('pharmacy.alternatives.index.cancel_button')</button>
                <button type='button' class='ph-btn primary' style='background:var(--ph-red);color:#fff;border-color:var(--ph-red);' data-ph-confirm-ok>@lang('pharmacy.alternatives.index.delete_button')</button>
            </div>
        </div>
    </div>

    <script>
        // Collapse/Accordion: طي/فتح بطاقات الأدوية
        (function () {
            document.querySelectorAll('[data-ph-toggle]').forEach(function (head) {
                head.addEventListener('click', function () {
                    var id = head.getAttribute('data-ph-toggle');
                    var body = document.getElementById(id);
                    if (!body) return;
                    var icon = head.querySelector('.ph-toggle-icon');
                    var expanded = head.getAttribute('aria-expanded') === 'true';
                    if (expanded) {
                        body.style.display = 'none';
                        head.setAttribute('aria-expanded', 'false');
                        if (icon) icon.style.transform = 'rotate(-90deg)';
                    } else {
                        body.style.display = '';
                        head.setAttribute('aria-expanded', 'true');
                        if (icon) icon.style.transform = 'rotate(0deg)';
                    }
                });
            });
        })();

        // Modal تأكيد الحذف (يحل محل confirm() التقليدي)
        (function () {
            var modal = document.getElementById('phDeleteConfirmModal');
            var msg = document.getElementById('phDeleteConfirmMessage');
            var okBtn = modal && modal.querySelector('[data-ph-confirm-ok]');
            var cancelBtns = modal && modal.querySelectorAll('[data-ph-confirm-cancel]');
            var pendingForm = null;

            if (!modal) return;

            function openModal(form, message) {
                pendingForm = form;
                msg.textContent = message;
                modal.classList.add('active');
            }
            function closeModal() {
                pendingForm = null;
                modal.classList.remove('active');
            }

            document.querySelectorAll('[data-ph-confirm-delete]').forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    var url = btn.getAttribute('data-url');
                    var message = btn.getAttribute('data-confirm') || '';
                    var form = document.getElementById('ph-delete-form-' + url.split('/').slice(-2).join('-'));
                    if (!form) {
                        // Fallback: ابحث عن طريق استخراج id من URL
                        var parts = url.split('/');
                        form = document.getElementById('ph-delete-form-' + parts[parts.length - 2] + '-' + parts[parts.length - 1]);
                    }
                    if (form) openModal(form, message);
                });
            });

            okBtn && okBtn.addEventListener('click', function () {
                if (pendingForm) pendingForm.submit();
                closeModal();
            });
            cancelBtns && cancelBtns.forEach(function (b) {
                b.addEventListener('click', closeModal);
            });
            // إغلاق بضغط Escape
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && modal.classList.contains('active')) closeModal();
            });
        })();
    </script>
@endsection