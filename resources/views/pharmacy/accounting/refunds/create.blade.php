<?php /** @var \App\Models\Pharmacy $pharmacy */ ?>
<?php /** @var \App\Models\Sale $sale */ ?>
<?php /** @var array $sale */ ?>
<?php /** @var bool $isDemo */ ?>
@extends('layouts.app')

@section('title', __('accounting.common.refund') . ' - ' . $sale['number'])

@section('breadcrumb')
    <span aria-hidden="true">/</span>
    <a href="{{ route('pharmacy.accounting.overview') }}">{{ __('accounting.nav.breadcrumb_accounting') }}</a>
    <span aria-hidden="true">/</span>
    <a href="{{ route('pharmacy.accounting.sales.index') }}">{{ __('accounting.sidebar.sales') }}</a>
    <span aria-hidden="true">/</span>
    <span aria-current="page">{{ $sale['number'] }}</span>
@endsection

@section('content')
    @vite(['resources/css/pages/pharmacy_hub.css', 'resources/css/pages/pharmacy_accounting.css', 'resources/js/accounting/accounting-shared.js'])

    <div class="ph-page">
        <div class="ph-page-title">
            <h1>@lang('accounting.common.refund')</h1>
            <p class="subtitle">{{ $sale['number'] }}</p>
        </div>

        <div class="ph-card">
            <h3 class="ph-card-title">@lang('accounting.common.summary')</h3>
            <div class="ph-grid">
                <div class="ph-grid-item">
                    <span class="label">@lang('accounting.common.subtotal')</span>
                    <span class="value">{{ account_money($sale['subtotal']) }}</span>
                </div>
                <div class="ph-grid-item">
                    <span class="label">@lang('accounting.common.discount')</span>
                    <span class="value">{{ account_money($sale['discount']) }}</span>
                </div>
                <div class="ph-grid-item">
                    <span class="label">@lang('accounting.common.total')</span>
                    <span class="value">{{ account_money($sale['total']) }}</span>
                </div>
                <div class="ph-grid-item">
                    <span class="label">@lang('accounting.common.paid')</span>
                    <span class="value">{{ account_money($sale['paid']) }}</span>
                </div>
                <div class="ph-grid-item">
                    <span class="label">@lang('accounting.common.remaining')</span>
                    <span class="value">{{ account_money($sale['remaining']) }}</span>
                </div>
                <div class="ph-grid-item">
                    <span class="label">@lang('accounting.common.refunded')</span>
                    <span class="value">{{ account_money($sale['paid'] - $sale['remaining']) }}</span>
                </div>
            </div>
        </div>

        <div class="ph-card">
            <h3 class="ph-card-title">@lang('accounting.common.select_items')</h3>
            <form method="POST" action="{{ route('api.pharmacy.accounting.refunds.store') }}" id="refund-form">
                @csrf
                <input type="hidden" name="sale_number" value="{{ $sale['number'] }}">
                <input type="hidden" name="refunded_at" value="{{ now()->format('Y-m-d') }}">

                <table class="ph-table">
                    <thead>
                        <tr>
                            <th>@lang('accounting.invoice.item')</th>
                            <th class="ac-num">@lang('accounting.common.qty')</th>
                            <th class="ac-num">@lang('accounting.common.unit_price')</th>
                            <th class="ac-num">@lang('accounting.common.total')</th>
                            <th class="ac-num">@lang('accounting.common.refundable')</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($sale['items'] as $item)
                            <tr>
                                <td>{{ $item['name'] }}</td>
                                <td class="ac-num">{{ $item['quantity'] }}</td>
                                <td class="ac-num">{{ account_money($item['unit_price']) }}</td>
                                <td class="ac-num">{{ account_money($item['line_total']) }}</td>
                                <td class="ac-num">{{ account_money($item['line_total']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <div class="ph-form-group">
                    <label for="reason">@lang('accounting.refunds.reason')</label>
                    <textarea name="reason" id="reason" rows="3" class="ph-textarea" placeholder="@lang('accounting.refunds.reason_placeholder')"></textarea>
                </div>

                <div class="ph-actions">
                    <a href="{{ route('pharmacy.accounting.sales.show', $sale['number']) }}" class="ph-btn outline">@lang('accounting.common.cancel')</a>
                    <button type="submit" class="ph-btn danger" data-ac-loading="@lang('accounting.common.processing')">
                        @lang('accounting.common.confirm_refund')
                    </button>
                </div>
            </form>
        </div>
    </div>

    @push('scripts')
        <script>
            document.getElementById('refund-form').addEventListener('submit', function(e) {
                if (!confirm('@lang('accounting.refunds.confirm')')) {
                    e.preventDefault();
                }
            });
        </script>
    @endpush
@endsection