@extends('layouts.app')

@section('title', __('pharmacy_import.title'))

@section('content')
    @vite(['resources/css/pages/pharmacy_hub.css', 'resources/css/pages/pharmacy_import.css'])

    @php
        $committed = $import->isCommitted();
    @endphp

    <div class='ph-page'>
        <div class='ph-head'>
            <div class='ph-page-title'>
                <h1>{{ $committed ? __('pharmacy_import.done_title') : __('pharmacy_import.title') }}</h1>
                <p>{{ $import->original_filename }}</p>
            </div>
            <div class='ph-actions'>
                <a href='{{ route('pharmacy.inventory.index') }}' class='ph-btn primary'>
                    <i class='fas fa-boxes-stacked'></i> @lang('pharmacy_import.done_back')
                </a>
                <a href='{{ route('pharmacy.inventory.import.index') }}' class='ph-btn ghost'>
                    <i class='fas fa-file-import'></i> @lang('pharmacy_import.title')
                </a>
            </div>
        </div>

        @if ($committed)
            <div class='pi-alert is-ok'>
                <i class='fas fa-circle-check'></i> @lang('pharmacy_import.done_title')
            </div>

            <div class='ph-card'>
                <div class='ph-card-head'>
                    <h2><i class='fas fa-chart-simple'></i> @lang('pharmacy_import.summary_title')</h2>
                </div>
                <div class='ph-card-body' style='padding:20px 22px;'>
                    <div class='pi-done-grid'>
                        <div class='pi-summary-card is-matched'>
                            <strong>{{ (int) ($summary['committed_rows'] ?? 0) }}</strong>
                            <span>@lang('pharmacy_import.done_committed_rows')</span>
                        </div>
                        <div class='pi-summary-card'>
                            <strong>{{ (int) ($summary['skipped_rows'] ?? 0) }}</strong>
                            <span>@lang('pharmacy_import.done_skipped_rows')</span>
                        </div>
                        <div class='pi-summary-card is-unmatched'>
                            <strong>{{ (int) ($summary['created_medicines'] ?? 0) }}</strong>
                            <span>@lang('pharmacy_import.done_created_medicines')</span>
                        </div>
                        <div class='pi-summary-card is-duplicate'>
                            <strong>{{ (int) ($summary['learned_aliases'] ?? 0) }}</strong>
                            <span>@lang('pharmacy_import.done_learned_aliases')</span>
                        </div>
                        <div class='pi-summary-card is-review'>
                            <strong>{{ (int) ($summary['low_stock_rows'] ?? 0) }}</strong>
                            <span>@lang('pharmacy_import.done_low_stock')</span>
                        </div>
                    </div>
                </div>
            </div>
        @else
            <div class='pi-alert {{ $import->isExpired() ? 'is-warn' : 'is-info' }}'>
                @if ($import->isExpired())
                    @lang('pharmacy_import.error_expired')
                @else
                    @lang('pharmacy_import.error_already_committed')
                @endif
            </div>

            <div class='ph-card'>
                <div class='ph-card-body' style='padding:20px 22px;'>
                    <p class='pi-inline-note'>
                        @lang('pharmacy_import.file_name'): {{ $import->original_filename }}
                        — @lang('pharmacy_import.export_status'): {{ $import->status }}
                    </p>
                    <div style='margin-block-start:14px;display:flex;gap:10px;flex-wrap:wrap;'>
                        <a href='{{ route('pharmacy.inventory.import.errors', ['import' => $import->uuid]) }}' class='ph-btn outline'>
                            <i class='fas fa-file-csv'></i> @lang('pharmacy_import.download_errors')
                        </a>
                        <a href='{{ route('pharmacy.inventory.import.index') }}' class='ph-btn ghost'>
                            <i class='fas fa-arrow-right'></i> @lang('pharmacy_import.back_to_inventory')
                        </a>
                    </div>
                </div>
            </div>
        @endif
    </div>
@endsection
