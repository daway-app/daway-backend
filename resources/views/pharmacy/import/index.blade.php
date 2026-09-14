@extends('layouts.app')

@section('title', __('pharmacy_import.title'))

@section('content')
    @vite(['resources/css/pages/pharmacy_hub.css', 'resources/css/pages/pharmacy_import.css'])

    @php
        $columnDescriptions = [
            'trade_name' => ['required' => true, 'desc' => __('pharmacy_import.col_trade_name')],
            'trade_name_ar' => ['required' => false, 'desc' => __('pharmacy_import.col_trade_name_ar')],
            'active_ingredient' => ['required' => false, 'desc' => __('pharmacy_import.col_active_ingredient')],
            'price' => ['required' => true, 'desc' => __('pharmacy_import.col_price')],
            'quantity' => ['required' => true, 'desc' => __('pharmacy_import.col_quantity')],
            'barcode' => ['required' => false, 'desc' => __('pharmacy_import.col_barcode')],
            'min_stock' => ['required' => false, 'desc' => __('pharmacy_import.col_min_stock')],
            'is_available' => ['required' => false, 'desc' => __('pharmacy_import.col_is_available')],
        ];
    @endphp

    <div class='ph-page'>
        <div class='ph-head'>
            <div class='ph-page-title'>
                <h1>@lang('pharmacy_import.title')</h1>
                <p>@lang('pharmacy_import.subtitle')</p>
            </div>
            <div class='ph-actions'>
                <a href='{{ route('pharmacy.inventory.index') }}' class='ph-btn ghost'>
                    <i class='fas fa-arrow-right'></i> @lang('pharmacy_import.back_to_inventory')
                </a>
            </div>
        </div>

        @if (session('success'))
            <div class='pi-alert is-ok'>{{ session('success') }}</div>
        @endif

        @if ($errors->any())
            <div class='pi-alert is-error'>
                <strong>@lang('pharmacy_import.upload_title')</strong>
                <ul style='margin:8px 0 0;padding-inline-start:20px;'>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class='pi-steps'>
            <div class='pi-step'><span class='pi-step-no'>1</span> @lang('pharmacy_import.step_download')</div>
            <div class='pi-step'><span class='pi-step-no'>2</span> @lang('pharmacy_import.step_upload')</div>
            <div class='pi-step'><span class='pi-step-no'>3</span> @lang('pharmacy_import.step_review')</div>
            <div class='pi-step'><span class='pi-step-no'>4</span> @lang('pharmacy_import.step_confirm')</div>
        </div>

        {{-- ============ 1) القالب ============ --}}
        <div class='ph-card'>
            <div class='ph-card-head'>
                <h2><i class='fas fa-file-arrow-down'></i> @lang('pharmacy_import.template_title')</h2>
                <p>@lang('pharmacy_import.template_hint')</p>
            </div>
            <div class='ph-card-body' style='padding:20px 22px;'>
                <div style='display:flex;gap:10px;flex-wrap:wrap;margin-block-end:20px;'>
                    <a href='{{ route('pharmacy.inventory.import.template', ['mode' => 'empty']) }}' class='ph-btn primary'>
                        <i class='fas fa-file-excel'></i> @lang('pharmacy_import.download_empty_template')
                    </a>
                    <a href='{{ route('pharmacy.inventory.import.template', ['mode' => 'current']) }}' class='ph-btn outline'>
                        <i class='fas fa-database'></i> @lang('pharmacy_import.download_current_inventory')
                    </a>
                </div>

                <h3 style='font-size:.9rem;margin:0 0 4px;'>@lang('pharmacy_import.column_guide_title')</h3>
                <p class='pi-inline-note' style='margin-block-end:12px;'>@lang('pharmacy_import.column_guide_hint')</p>

                <div class='pi-columns'>
                    @foreach ($columns as $column)
                        @php $meta = $columnDescriptions[$column] ?? ['required' => false, 'desc' => $column]; @endphp
                        <div class='pi-column'>
                            <code>{{ $column }}</code>
                            @if ($meta['required'])
                                <span class='pi-req' title='@lang('pharmacy_import.column_guide_hint')'>*</span>
                            @endif
                            <span class='pi-col-desc'>{{ $meta['desc'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- ============ 2) الرفع ============ --}}
        <div class='ph-card'>
            <div class='ph-card-head'>
                <h2><i class='fas fa-cloud-arrow-up'></i> @lang('pharmacy_import.upload_title')</h2>
                <p>@lang('pharmacy_import.upload_hint', ['max' => $maxFileMb, 'rows' => number_format($maxRows)])</p>
            </div>
            <div class='ph-card-body' style='padding:20px 22px;'>
                @include('pharmacy.import._rate_limit_note', ['rateLimit' => $rateLimit ?? null])

                <form method='POST' action='{{ route('pharmacy.inventory.import.preview') }}' enctype='multipart/form-data' id='pi-upload-form'>
                    @csrf

                    <label for='pi-file' class='pi-drop' id='pi-drop'>
                        <i class='fas fa-file-import pi-drop-icon'></i>
                        <p>@lang('pharmacy_import.upload_choose') — xlsx / xls / csv</p>
                        <input type='file' id='pi-file' name='file' class='pi-file-input'
                               accept='.xlsx,.xls,.csv,text/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' required>
                        <div class='pi-file-name' id='pi-file-name'></div>
                    </label>

                    <div style='margin-block-start:18px;'>
                        <button type='submit' class='ph-btn primary' id='pi-submit'>
                            <i class='fas fa-magnifying-glass-chart'></i> @lang('pharmacy_import.upload_submit')
                        </button>
                        <span class='pi-inline-note' id='pi-processing' style='margin-inline-start:12px;display:none;'>
                            @lang('pharmacy_import.upload_processing')
                        </span>
                    </div>
                </form>
            </div>
        </div>

        {{-- ============ آخر الجلسات ============ --}}
        @if ($recent->isNotEmpty())
            <div class='ph-card'>
                <div class='ph-card-head'>
                    <h2><i class='fas fa-clock-rotate-left'></i> @lang('pharmacy_import.table_title')</h2>
                </div>
                <div class='ph-card-body ph-table-wrap' style='padding:0;'>
                    <table class='ph-table pi-recent'>
                        <thead>
                            <tr>
                                <th>@lang('pharmacy_import.file_name')</th>
                                <th>@lang('pharmacy_import.export_status')</th>
                                <th>@lang('pharmacy_import.summary_total')</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($recent as $session)
                                <tr>
                                    <td>{{ $session->original_filename }}</td>
                                    <td>
                                        <span class='pi-status {{ $session->isCommitted() ? 'is-ok' : ($session->isExpired() ? 'is-error' : 'is-teal') }}'>
                                            {{ $session->status }}
                                        </span>
                                    </td>
                                    <td>{{ (int) $session->total_rows }}</td>
                                    <td>
                                        @if ($session->canCommit())
                                            <a href='{{ route('pharmacy.inventory.import.show', ['import' => $session->uuid]) }}' class='ph-btn ghost'>
                                                <i class='fas fa-eye'></i> @lang('pharmacy_import.step_review')
                                            </a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>

    @push('scripts')
        <script>
            (function () {
                var input = document.getElementById('pi-file');
                var drop = document.getElementById('pi-drop');
                var nameBox = document.getElementById('pi-file-name');
                var form = document.getElementById('pi-upload-form');
                var submit = document.getElementById('pi-submit');
                var processing = document.getElementById('pi-processing');

                if (!input) { return; }

                function showName() {
                    nameBox.textContent = input.files && input.files.length ? input.files[0].name : '';
                }

                input.addEventListener('change', showName);

                ['dragenter', 'dragover'].forEach(function (evt) {
                    drop.addEventListener(evt, function (e) {
                        e.preventDefault();
                        drop.classList.add('is-dragging');
                    });
                });

                ['dragleave', 'drop'].forEach(function (evt) {
                    drop.addEventListener(evt, function (e) {
                        e.preventDefault();
                        drop.classList.remove('is-dragging');
                    });
                });

                drop.addEventListener('drop', function (e) {
                    if (e.dataTransfer && e.dataTransfer.files.length) {
                        input.files = e.dataTransfer.files;
                        showName();
                    }
                });

                // منع الرفع المزدوج: نُعطّل الزر بعد أول إرسال صالح
                form.addEventListener('submit', function () {
                    if (!input.files || !input.files.length) { return; }
                    submit.disabled = true;
                    submit.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                    processing.style.display = 'inline';
                });
            })();
        </script>
    @endpush
@endsection
