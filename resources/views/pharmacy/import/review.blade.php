@extends('layouts.app')

@section('title', __('pharmacy_import.title'))

@section('content')
    @vite(['resources/css/pages/pharmacy_hub.css', 'resources/css/pages/pharmacy_import.css'])

    @php
        // خريطة حالة الصف → صنف CSS. الحالة تُعرض دائماً كنص مع الشارة،
        // فاللون تأكيد إضافي لا القناة الوحيدة للمعنى (متطلّب إمكانية الوصول).
        $statusClass = function (string $status): string {
            return match ($status) {
                \App\Models\InventoryImport::ROW_EXACT_EN,
                \App\Models\InventoryImport::ROW_EXACT_AR,
                \App\Models\InventoryImport::ROW_ALIAS => 'is-ok',
                \App\Models\InventoryImport::ROW_MOH => 'is-teal',
                \App\Models\InventoryImport::ROW_FUZZY,
                \App\Models\InventoryImport::ROW_REVIEW => 'is-warn',
                \App\Models\InventoryImport::ROW_UNMATCHED => 'is-info',
                \App\Models\InventoryImport::ROW_DUPLICATE => 'is-teal',
                \App\Models\InventoryImport::ROW_INVALID => 'is-error',
                default => '',
            };
        };

        $statusLabel = function (string $status): string {
            $key = 'pharmacy_import.status_'.$status;

            return __($key) === $key ? $status : __($key);
        };

        // الفائز في كل مجموعة مكرّرة حسب قرار الدمج المحفوظ
        $winnerRow = [];

        foreach ($groups as $groupKey => $rowNumbers) {
            $decision = $merges[$groupKey] ?? null;

            if ($decision === null || $decision === \App\Models\InventoryImport::MERGE_SKIP_ALL) {
                continue;
            }

            $numbers = array_map('intval', (array) $rowNumbers);
            $winnerRow[$groupKey] = $decision === \App\Models\InventoryImport::MERGE_KEEP_FIRST ? min($numbers) : max($numbers);
        }

        // القرار الافتراضي لكل صف — مشتقّ من قدرات الصف لا من حالة العرض،
        // لأن صفوف التكرار تُعاد كتابة حالتها إلى DUPLICATE وتفقد تصنيفها الأصلي.
        $defaultDecision = function (array $row): string {
            if (($row['errors'] ?? []) !== []) {
                return \App\Models\InventoryImport::DECISION_SKIP;
            }

            // إن حُسم مسبقاً على الخادم نحترم المحفوظ
            $saved = (string) ($row['decision'] ?? \App\Models\InventoryImport::DECISION_PENDING);

            if ($saved !== \App\Models\InventoryImport::DECISION_PENDING) {
                return $saved;
            }

            if ($row['medicine_id'] !== null) {
                return \App\Models\InventoryImport::DECISION_LINK;
            }

            if ($row['proposed_moh_id'] !== null) {
                return \App\Models\InventoryImport::DECISION_CREATE;
            }

            foreach ((array) ($row['suggestions'] ?? []) as $suggestion) {
                if (! empty($suggestion['medicine_id'])) {
                    return \App\Models\InventoryImport::DECISION_LINK;
                }
            }

            foreach ((array) ($row['suggestions'] ?? []) as $suggestion) {
                if (! empty($suggestion['moh_id'])) {
                    return \App\Models\InventoryImport::DECISION_CREATE;
                }
            }

            return \App\Models\InventoryImport::DECISION_PENDING;
        };

        // الدواء المقترح للربط: المطابق مباشرة، أو أول اقتراح من الكتالوج المحلي
        $linkTarget = function (array $row): ?array {
            if ($row['medicine_id'] !== null) {
                return ['id' => $row['medicine_id'], 'name' => $row['resolved_name'] ?? '', 'sub' => $row['resolved_name_ar'] ?? ''];
            }

            foreach ((array) ($row['suggestions'] ?? []) as $suggestion) {
                if (! empty($suggestion['medicine_id'])) {
                    return ['id' => $suggestion['medicine_id'], 'name' => $suggestion['name'] ?? '', 'sub' => $suggestion['name_ar'] ?? ''];
                }
            }

            return null;
        };

        // عنصر وزارة الصحة المقترح للإنشاء: المحفوظ على الخادم، أو أول اقتراح وزاري
        $createTarget = function (array $row): ?array {
            if ($row['proposed_moh_id'] !== null) {
                return ['moh_id' => $row['proposed_moh_id'], 'name' => $row['resolved_name'] ?? ''];
            }

            foreach ((array) ($row['suggestions'] ?? []) as $suggestion) {
                if (! empty($suggestion['moh_id'])) {
                    return ['moh_id' => $suggestion['moh_id'], 'name' => $suggestion['name'] ?? ''];
                }
            }

            return null;
        };
    @endphp

    <div class='ph-page'>
        <div class='ph-head'>
            <div class='ph-page-title'>
                <h1>@lang('pharmacy_import.summary_title')</h1>
                <p>{{ $import->original_filename }}</p>
            </div>
            <div class='ph-actions'>
                <a href='{{ route('pharmacy.inventory.import.errors', ['import' => $import->uuid]) }}' class='ph-btn outline'>
                    <i class='fas fa-file-csv'></i> @lang('pharmacy_import.download_errors')
                </a>
                <a href='{{ route('pharmacy.inventory.import.index') }}' class='ph-btn outline'>
                    <i class='fas fa-arrow-right'></i> @lang('pharmacy_import.back_to_inventory')
                </a>
            </div>
        </div>

        @if ($errors->any())
            <div class='pi-alert is-error'>
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        @if ($unknownColumns !== [])
            <div class='pi-alert is-warn'>
                {{ __('pharmacy_import.unknown_columns_notice', ['columns' => implode(', ', $unknownColumns)]) }}
            </div>
        @endif

        {{-- ============ الملخّص ============ --}}
        <div class='pi-summary'>
            <div class='pi-summary-card'>
                <strong>{{ (int) $import->total_rows }}</strong>
                <span>@lang('pharmacy_import.summary_total')</span>
            </div>
            <div class='pi-summary-card is-matched'>
                <strong>{{ (int) $import->matched_rows }}</strong>
                <span>@lang('pharmacy_import.summary_matched')</span>
                <small>@lang('pharmacy_import.summary_matched_hint')</small>
            </div>
            <div class='pi-summary-card is-review'>
                <strong>{{ (int) $import->review_rows }}</strong>
                <span>@lang('pharmacy_import.summary_review')</span>
                <small>@lang('pharmacy_import.summary_review_hint')</small>
            </div>
            <div class='pi-summary-card is-unmatched'>
                <strong>{{ (int) $import->unmatched_rows }}</strong>
                <span>@lang('pharmacy_import.summary_unmatched')</span>
                <small>@lang('pharmacy_import.summary_unmatched_hint')</small>
            </div>
            <div class='pi-summary-card is-duplicate'>
                <strong>{{ (int) $import->duplicate_rows }}</strong>
                <span>@lang('pharmacy_import.summary_duplicate')</span>
                <small>@lang('pharmacy_import.summary_duplicate_hint')</small>
            </div>
            <div class='pi-summary-card is-error'>
                <strong>{{ (int) $import->error_rows }}</strong>
                <span>@lang('pharmacy_import.summary_error')</span>
                <small>@lang('pharmacy_import.summary_error_hint')</small>
            </div>
        </div>

        {{-- ============ مجموعات التكرار ============ --}}
        @if ($groups !== [])
            <div class='ph-card'>
                <div class='ph-card-head'>
                    <h2><i class='fas fa-clone'></i> @lang('pharmacy_import.merge_title')</h2>
                    <p>@lang('pharmacy_import.merge_hint')</p>
                </div>
                <div class='ph-card-body' style='padding:18px 22px;'>
                    @foreach ($groups as $groupKey => $rowNumbers)
                        @php
                            $numbers = array_map('intval', (array) $rowNumbers);
                            $firstRow = collect($rows)->firstWhere('row', $numbers[0] ?? 0);
                            $groupName = $firstRow['resolved_name'] ?? ($firstRow['input']['trade_name'] ?? $groupKey);
                        @endphp
                        <div class='pi-merge' data-pi-merge-group='{{ $groupKey }}'>
                            <div class='pi-merge-head'>
                                <strong>{{ __('pharmacy_import.merge_group_label', ['name' => $groupName]) }}</strong>
                                <span>{{ __('pharmacy_import.merge_rows_label', ['rows' => implode(', ', $numbers)]) }}</span>
                            </div>
                            <div class='pi-merge-options'>
                                @foreach ([
                                    \App\Models\InventoryImport::MERGE_KEEP_LAST => 'merge_keep_last',
                                    \App\Models\InventoryImport::MERGE_KEEP_FIRST => 'merge_keep_first',
                                    \App\Models\InventoryImport::MERGE_SKIP_ALL => 'merge_skip_all',
                                ] as $value => $labelKey)
                                    <label>
                                        <input type='radio' name='merge_{{ $groupKey }}' value='{{ $value }}'
                                               data-pi-merge-input
                                               @checked(($merges[$groupKey] ?? null) === $value)>
                                        @lang('pharmacy_import.'.$labelKey)
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- ============ جدول الأسطر ============ --}}
        <div class='ph-card'>
            <div class='ph-card-head'>
                <h2><i class='fas fa-list-check'></i> @lang('pharmacy_import.table_title')</h2>
            </div>

            <div style='padding:16px 22px 0;'>
                <div class='pi-filters'>
                    <button type='button' class='pi-chip active' data-pi-filter='all'>@lang('pharmacy_import.filter_all')</button>
                    <button type='button' class='pi-chip' data-pi-filter='decision'>@lang('pharmacy_import.filter_needs_decision')</button>
                    <button type='button' class='pi-chip' data-pi-filter='errors'>@lang('pharmacy_import.filter_errors')</button>
                    <span class='pi-inline-note' id='pi-pending-count'></span>
                </div>
            </div>

            <div class='ph-card-body ph-table-wrap' style='padding:0;'>
                <table class='ph-table'>
                    <thead>
                        <tr>
                            <th style='width:70px;'>@lang('pharmacy_import.row_number')</th>
                            <th>@lang('pharmacy_import.input_name')</th>
                            <th>@lang('pharmacy_import.matched_medicine')</th>
                            <th>@lang('pharmacy_import.row_status')</th>
                            <th style='min-width:260px;'>@lang('pharmacy_import.row_decision')</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php
                                $rowNumber = (int) $row['row'];
                                $status = (string) $row['status'];
                                $input = (array) $row['input'];
                                $hasErrors = ($row['errors'] ?? []) !== [];
                                $isWinner = true;

                                if (($row['duplicate_group'] ?? null) !== null && isset($groups[$row['duplicate_group']])) {
                                    $isWinner = ($winnerRow[$row['duplicate_group']] ?? null) === $rowNumber;
                                }

                                $decision = $defaultDecision($row);
                                $link = $linkTarget($row);
                                $create = $createTarget($row);
                                $needsDecision = $decision === \App\Models\InventoryImport::DECISION_PENDING;

                                // فئة الفلتر: هل يحتاج قراراً؟ (لا قرار افتراضي موجود)
                                $filterClass = $needsDecision ? 'decision' : ($hasErrors ? 'errors' : '');
                            @endphp
                            <tr data-pi-row='{{ $rowNumber }}'
                                data-pi-filter-class='{{ $filterClass }}'
                                data-pi-has-errors='{{ $hasErrors ? '1' : '0' }}'
                                data-pi-needs-decision='{{ $needsDecision ? '1' : '0' }}'
                                data-pi-medicine-id='{{ $link['id'] ?? '' }}'
                                data-pi-moh-id='{{ $create['moh_id'] ?? '' }}'>
                                <td><strong>{{ $rowNumber }}</strong></td>

                                <td>
                                    <span class='pi-row-input'>
                                        {{ $input['trade_name'] ?? '' }}
                                        @if (! empty($input['trade_name_ar']))
                                            <small>{{ $input['trade_name_ar'] }}</small>
                                        @endif
                                    </span>
                                    <p class='pi-inline-note' style='margin-block-start:4px;'>
                                        @lang('pharmacy_import.col_quantity'): {{ $input['quantity'] ?? '—' }} ·
                                        @lang('pharmacy_import.col_price'): {{ $input['price'] ?? '—' }}
                                    </p>
                                </td>

                                <td>
                                    @if (! empty($row['resolved_name']))
                                        <span class='pi-resolved'><b>{{ $row['resolved_name'] }}</b>
                                            @if (! empty($row['resolved_name_ar']))
                                                <br><small>{{ $row['resolved_name_ar'] }}</small>
                                            @endif
                                        </span>
                                    @else
                                        <span class='pi-inline-note'>—</span>
                                    @endif

                                    @if (($row['suggestions'] ?? []) !== [])
                                        <ul class='pi-suggestions'>
                                            @foreach ($row['suggestions'] as $suggestion)
                                                <li>
                                                    <i class='fas fa-lightbulb'></i>
                                                    {{ $suggestion['name'] ?? '' }}
                                                    @if (! empty($suggestion['name_ar']))
                                                        ({{ $suggestion['name_ar'] }})
                                                    @endif
                                                    @if (! empty($suggestion['official_price']))
                                                        — {{ $suggestion['official_price'] }}
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif

                                    @if (($row['warnings'] ?? []) !== [])
                                        <ul class='pi-suggestions'>
                                            @foreach ($row['warnings'] as $warning)
                                                <li>
                                                    <i class='fas fa-triangle-exclamation' style='color:var(--ph-orange);'></i>
                                                    @lang('pharmacy_import.warn_'.$warning)
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </td>

                                <td>
                                    <span class='pi-status {{ $statusClass($status) }}'>{{ $statusLabel($status) }}</span>
                                    @if (($row['duplicate_group'] ?? null) !== null)
                                        <p class='pi-inline-note' style='margin-block-start:6px;'>
                                            {{ $isWinner ? __('pharmacy_import.duplicate_winner') : __('pharmacy_import.duplicate_loser') }}
                                        </p>
                                    @endif
                                </td>

                                <td>
                                    @if ($hasErrors)
                                        <ul class='pi-suggestions' style='margin:0;'>
                                            @foreach ($row['errors'] as $error)
                                                <li style='color:var(--ph-red);'>@lang('pharmacy_import.err_'.$error)</li>
                                            @endforeach
                                        </ul>
                                        <p class='pi-inline-note' style='margin-block-start:6px;'>@lang('pharmacy_import.decision_skip')</p>
                                    @else
                                        <div class='pi-decide'>
                                            <label>
                                                <input type='radio' name='decision_{{ $rowNumber }}' value='{{ \App\Models\InventoryImport::DECISION_LINK }}'
                                                       data-pi-decision
                                                       @checked($decision === \App\Models\InventoryImport::DECISION_LINK)>
                                                @lang('pharmacy_import.decision_link')
                                            </label>
                                            <label>
                                                <input type='radio' name='decision_{{ $rowNumber }}' value='{{ \App\Models\InventoryImport::DECISION_CREATE }}'
                                                       data-pi-decision
                                                       @checked($decision === \App\Models\InventoryImport::DECISION_CREATE)>
                                                @lang('pharmacy_import.decision_create')
                                            </label>
                                            <label>
                                                <input type='radio' name='decision_{{ $rowNumber }}' value='{{ \App\Models\InventoryImport::DECISION_SKIP }}'
                                                       data-pi-decision
                                                       @checked($decision === \App\Models\InventoryImport::DECISION_SKIP)>
                                                @lang('pharmacy_import.decision_skip')
                                            </label>

                                            <div class='pi-search-box' data-pi-search-wrap>
                                                <input type='text' data-pi-search
                                                       placeholder='@lang('pharmacy_import.decision_search_placeholder')'
                                                       value='{{ $link['name'] ?? '' }}' autocomplete='off'>
                                                <div class='pi-search-results' data-pi-search-results></div>
                                            </div>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- ============ شريط التأكيد ============ --}}
            <div class='pi-confirm-bar'>
                <div>
                    <div class='pi-pending-note' id='pi-pending-note'></div>
                    <div class='pi-ready-note' id='pi-ready-note'></div>
                    <p class='pi-inline-note' style='margin-block-start:6px;'>@lang('pharmacy_import.confirm_hint')</p>
                    @include('pharmacy.import._rate_limit_note', ['rateLimit' => $rateLimit ?? null])
                </div>
                <div class='pi-confirm-actions'>
                    <button type='button' class='ph-btn ghost' id='pi-save'>
                        <i class='fas fa-floppy-disk'></i> @lang('pharmacy_import.row_decision')
                    </button>
                    <button type='button' class='ph-btn primary' id='pi-commit'>
                        <i class='fas fa-check-double'></i> @lang('pharmacy_import.confirm_commit')
                    </button>
                    <form method='POST' action='{{ route('pharmacy.inventory.import.cancel', ['import' => $import->uuid]) }}'
                          onsubmit="return confirm('@lang('pharmacy_import.cancel_confirm')');">
                        @csrf
                        <button type='submit' class='ph-btn danger'>
                            <i class='fas fa-xmark'></i> @lang('pharmacy_import.cancel_import')
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- نموذج التنفيذ: يُرسَل بعد نجاح حفظ القرارات --}}
    <form method='POST' action='{{ route('pharmacy.inventory.import.commit', ['import' => $import->uuid]) }}' id='pi-commit-form' class='pi-hidden'>
        @csrf
    </form>

    @php
        // ⚠️ لا تستخدم @json([...]) مباشرةً هنا: توجيه @json يقسم التعبير على
        // أول فاصلة (explode(',', ...)) ويأخذ الجزء الأول فقط — فأي مصفوفة
        // متعددة المفاتيح تُقصّ بصمت وتُنتج PHP غير صالح. نبني المصفوفة أولاً
        // ثم نمرّرها كمتغيّر واحد بلا فواصل.
        $piConfig = [
            'decideUrl' => route('pharmacy.inventory.import.decide', ['import' => $import->uuid]),
            'searchUrl' => route('pharmacy.medicines.search'),
            'csrf' => csrf_token(),
            'creating' => (int) ($decisions['to_create'] ?? 0),
            'messages' => [
                'pending' => __('pharmacy_import.confirm_pending_notice', ['count' => '__COUNT__']),
                'ready' => __('pharmacy_import.confirm_irreversible'),
                'creating' => __('pharmacy_import.confirm_creating_notice', ['count' => '__COUNT__']),
                'creatingConfirm' => __('pharmacy_import.decision_create_hint'),
                'noResults' => __('pharmacy_import.decision_no_results'),
                'error' => __('pharmacy_import.error_commit_failed'),
                'saving' => __('pharmacy_import.upload_processing'),
            ],
        ];
    @endphp

    <script id='pi-config' type='application/json'>
        @json($piConfig)
    </script>

    @push('scripts')
        @include('pharmacy.import._rate_limit')
        <script>
            (function () {
                var configEl = document.getElementById('pi-config');
                if (!configEl) { return; }

                var config = JSON.parse(configEl.textContent);
                var commitForm = document.getElementById('pi-commit-form');
                var saveBtn = document.getElementById('pi-save');
                var commitBtn = document.getElementById('pi-commit');
                var pendingNote = document.getElementById('pi-pending-note');
                var readyNote = document.getElementById('pi-ready-note');
                var pendingCountEl = document.getElementById('pi-pending-count');

                var rows = Array.prototype.slice.call(document.querySelectorAll('[data-pi-row]'));
                var merges = Array.prototype.slice.call(document.querySelectorAll('[data-pi-merge-input]'));

                /* ---------- جمع القرارات من الواجهة ---------- */

                function collectDecisions() {
                    var out = [];

                    rows.forEach(function (tr) {
                        var rowNumber = parseInt(tr.getAttribute('data-pi-row'), 10);
                        var checked = tr.querySelector('[data-pi-decision]:checked');

                        // صف بأخطاء: لا محرّر له — يُرسل "تجاهل" صراحةً
                        if (!checked) {
                            if (tr.getAttribute('data-pi-has-errors') === '1') {
                                out.push({ row: rowNumber, action: 'skip' });
                            }
                            return;
                        }

                        var item = { row: rowNumber, action: checked.value };

                        if (checked.value === 'link') {
                            var mid = tr.getAttribute('data-pi-medicine-id');
                            item.medicine_id = mid ? parseInt(mid, 10) : null;
                        }

                        if (checked.value === 'create') {
                            var moh = tr.getAttribute('data-pi-moh-id');
                            if (moh) { item.moh_id = parseInt(moh, 10); }
                        }

                        out.push(item);
                    });

                    return out;
                }

                function collectMerges() {
                    var out = {};

                    merges.forEach(function (input) {
                        if (!input.checked) { return; }
                        var group = input.name.replace(/^merge_/, '');
                        out[group] = input.value;
                    });

                    return out;
                }

                /* ---------- حساب ما يحتاج قراراً ---------- */

                function pendingRows() {
                    return rows.filter(function (tr) {
                        if (tr.getAttribute('data-pi-has-errors') === '1') { return false; }
                        return !tr.querySelector('[data-pi-decision]:checked');
                    });
                }

                function groupsWithoutMerge() {
                    var groups = {};

                    merges.forEach(function (input) {
                        var group = input.name.replace(/^merge_/, '');
                        groups[group] = groups[group] || false;
                        if (input.checked) { groups[group] = true; }
                    });

                    return Object.keys(groups).filter(function (k) { return !groups[k]; });
                }

                function refreshCounters() {
                    var pending = pendingRows().length;
                    var noMerge = groupsWithoutMerge().length;
                    var blocking = pending + noMerge;

                    if (blocking > 0) {
                        pendingNote.textContent = config.messages.pending.replace('__COUNT__', blocking);
                        pendingNote.style.display = '';
                        readyNote.textContent = '';
                    } else {
                        pendingNote.textContent = '';
                        pendingNote.style.display = 'none';
                        readyNote.textContent = config.messages.ready;
                    }

                    pendingCountEl.textContent = blocking > 0
                        ? config.messages.pending.replace('__COUNT__', blocking)
                        : '';

                    commitBtn.disabled = false;
                }

                /* ---------- الفلترة ---------- */

                document.querySelectorAll('[data-pi-filter]').forEach(function (chip) {
                    chip.addEventListener('click', function () {
                        document.querySelectorAll('[data-pi-filter]').forEach(function (c) {
                            c.classList.remove('active');
                        });
                        chip.classList.add('active');

                        var mode = chip.getAttribute('data-pi-filter');

                        rows.forEach(function (tr) {
                            var show = true;

                            if (mode === 'decision') {
                                show = tr.getAttribute('data-pi-needs-decision') === '1';
                            } else if (mode === 'errors') {
                                show = tr.getAttribute('data-pi-has-errors') === '1';
                            }

                            tr.classList.toggle('pi-hidden', !show);
                        });
                    });
                });

                /* ---------- بحث الكتالوج لكل صف ---------- */

                rows.forEach(function (tr) {
                    var searchInput = tr.querySelector('[data-pi-search]');
                    var resultsBox = tr.querySelector('[data-pi-search-results]');
                    var linkRadio = tr.querySelector('[data-pi-decision][value="link"]');

                    if (!searchInput || !resultsBox) { return; }

                    var timer = null;

                    function close() {
                        resultsBox.classList.remove('is-open');
                        resultsBox.innerHTML = '';
                    }

                    function open(list) {
                        resultsBox.innerHTML = '';

                        if (!list.length) {
                            var empty = document.createElement('div');
                            empty.className = 'pi-no-results';
                            empty.textContent = config.messages.noResults;
                            resultsBox.appendChild(empty);
                        } else {
                            list.forEach(function (item) {
                                // رسالة نظام (مثل تجاوز حد المعدل) لا نتيجة قابلة للاختيار
                                if (item.type === 'note') {
                                    var note = document.createElement('div');
                                    note.className = 'pi-no-results';
                                    note.textContent = item.text || '';
                                    resultsBox.appendChild(note);
                                    return;
                                }

                                if (item.type !== 'medicine') { return; }

                                var btn = document.createElement('button');
                                btn.type = 'button';
                                btn.innerHTML = '<span></span><small></small>';
                                btn.querySelector('span').textContent = item.name || '';
                                btn.querySelector('small').textContent = item.sub || '';

                                btn.addEventListener('click', function () {
                                    tr.setAttribute('data-pi-medicine-id', item.id);
                                    searchInput.value = item.name || '';
                                    if (linkRadio) { linkRadio.checked = true; }
                                    close();
                                    refreshCounters();
                                });

                                resultsBox.appendChild(btn);
                            });
                        }

                        resultsBox.classList.add('is-open');
                    }

                    searchInput.addEventListener('input', function () {
                        var q = searchInput.value.trim();

                        // تغيير النص يدوياً يُبطل الاختيار السابق حتى لا يبقى معرّف قديم
                        tr.setAttribute('data-pi-medicine-id', '');

                        if (q.length < 2) { close(); refreshCounters(); return; }

                        clearTimeout(timer);
                        timer = setTimeout(function () {
                            // نمرّ عبر المساعد الموحّد: 429 هنا لا يجب أن يظهر
                            // كـ «لا نتائج مطابقة» — وهذا ما كان يحدث سابقاً.
                            window.DawayRateLimit.request(config.searchUrl + '?q=' + encodeURIComponent(q), {
                                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                                credentials: 'same-origin'
                            })
                                .then(function (res) {
                                    if (res.status === 429) {
                                        open([{ type: 'note', text: res.message }]);
                                        return;
                                    }

                                    open(Array.isArray(res.body) ? res.body : []);
                                })
                                .catch(close);
                        }, 250);

                        refreshCounters();
                    });

                    searchInput.addEventListener('blur', function () {
                        setTimeout(close, 180);
                    });

                    searchInput.addEventListener('focus', function () {
                        if (resultsBox.children.length) { resultsBox.classList.add('is-open'); }
                    });
                });

                /* ---------- تحديث العدّادات عند أي تغيير ---------- */

                document.querySelectorAll('[data-pi-decision]').forEach(function (radio) {
                    radio.addEventListener('change', refreshCounters);
                });

                merges.forEach(function (input) {
                    input.addEventListener('change', refreshCounters);
                });

                /* ---------- الحفظ ---------- */

                function save() {
                    var payload = {
                        rows: collectDecisions(),
                        merges: collectMerges()
                    };

                    // المساعد الموحّد يتكفّل بـ 429: يقرأ Retry-After، ويعيد
                    // المحاولة مرة واحدة إذا كان الانتظار قصيراً، وإلا يعيد
                    // رسالة عربية واضحة بالثواني بدل «فشل التنفيذ» المبهمة.
                    return window.DawayRateLimit.request(config.decideUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': config.csrf,
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify(payload)
                    });
                }

                /** نص الخطأ المعروض: رسالة الحد أولاً، ثم رسالة الخادم، ثم العام. */
                function errorText(res) {
                    if (!res) { return config.messages.error; }

                    return res.message
                        || (res.body && res.body.message)
                        || config.messages.error;
                }

                function busy(btn, on) {
                    btn.disabled = on;
                    btn.dataset.label = btn.dataset.label || btn.innerHTML;
                    btn.innerHTML = on ? '<i class="fas fa-spinner fa-spin"></i>' : btn.dataset.label;
                }

                saveBtn.addEventListener('click', function () {
                    busy(saveBtn, true);
                    save()
                        .then(function (res) {
                            if (!res.ok) { alert(errorText(res)); return; }
                            refreshCounters();
                        })
                        .catch(function () { alert(config.messages.error); })
                        .finally(function () { busy(saveBtn, false); });
                });

                commitBtn.addEventListener('click', function () {
                    var blocking = pendingRows().length + groupsWithoutMerge().length;

                    if (blocking > 0) {
                        alert(config.messages.pending.replace('__COUNT__', blocking));
                        return;
                    }

                    busy(commitBtn, true);

                    save()
                        .then(function (res) {
                            if (!res.ok) {
                                busy(commitBtn, false);
                                alert(errorText(res));
                                return;
                            }

                            // تأكيد صريح لإنشاء أدوية جديدة في الكتالوج العام
                            var created = (res.body.decisions && res.body.decisions.to_create) || 0;

                            if (created > 0) {
                                var msg = config.messages.creating.replace('__COUNT__', created)
                                    + '\n\n' + config.messages.creatingConfirm;
                                if (!confirm(msg)) {
                                    busy(commitBtn, false);
                                    return;
                                }
                            }

                            commitForm.submit();
                        })
                        .catch(function () {
                            busy(commitBtn, false);
                            alert(config.messages.error);
                        });
                });

                refreshCounters();
            })();
        </script>
    @endpush
@endsection
