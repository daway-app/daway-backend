<?php /** @var \App\Models\Pharmacy $pharmacy */ ?>
<?php /** @var \App\Models\Refund $refund */ ?>
<?php /** @var array $sale */ ?>
<?php /** @var bool $isDemo */ ?>
@extends('layouts.app')

@section('title', __('accounting.refunds.title') . ' #' . $refund->id)

@section('breadcrumb')
    <span aria-hidden="true">/</span>
    <a href="{{ route('pharmacy.accounting.overview') }}">{{ __('accounting.nav.breadcrumb_accounting') }}</a>
    <span aria-hidden="true">/</span>
    <a href="{{ route('pharmacy.accounting.refunds.index') }}">{{ __('accounting.sidebar.refunds') }}</a>
    <span aria-hidden="true">/</span>
    <span aria-current="page">{{ __('accounting.refunds.show.refund_number', ['number' => '#' . $refund->id]) }}</span>
@endsection

@section('content')
    @vite(['resources/css/pages/pharmacy_hub.css', 'resources/css/pages/pharmacy_accounting.css', 'resources/js/accounting/accounting-shared.js'])

    <div class="ph-page">
        <div class="ph-page-title">
            <h1>@lang('accounting.refunds.show.title')</h1>
            <p class="subtitle">{{ $refund->number ?? '#' . $refund->id }}</p>
        </div>

        <div class="ph-card">
            <h3 class="ph-card-title">@lang('accounting.refunds.show.sale_info')</h3>
            <div class="ph-grid">
                <div class="ph-grid-item">
                    <span class="label">@lang('accounting.invoice.number')</span>
                    <span class="value">
                        <a href="{{ route('pharmacy.accounting.sales.show', $refund->sale->number) }}">{{ $refund->sale->number }}</a>
                    </span>
                </div>
                <div class="ph-grid-item">
                    <span class="label">@lang('accounting.common.date')</span>
                    <span class="value">{{ $refund->refunded_at->format('Y-m-d') }}</span>
                </div>
                <div class="ph-grid-item">
                    <span class="label">@lang('accounting.common.total')</span>
                    <span class="value">{{ account_money($refund->sale->total) }}</span>
                </div>
                <div class="ph-grid-item">
                    <span class="label">@lang('accounting.common.refunded')</span>
                    <span class="value">{{ account_money($refund->amount) }}</span>
                </div>
                <div class="ph-grid-item">
                    <span class="label">@lang('accounting.common.status')</span>
                    <span class="value">
                        <span class="ph-badge {{ $refund->status === 'completed' ? 'refunded' : 'pending' }}">
                            @lang('accounting.refunds.status.' . $refund->status)
                        </span>
                    </span>
                </div>
                <div class="ph-grid-item">
                    <span class="label">@lang('accounting.invoice.created_by')</span>
                    <span class="value">{{ $refund->createdBy->name ?? '-' }}</span>
                </div>
            </div>
        </div>

        <div class="ph-card">
            <h3 class="ph-card-title">@lang('accounting.refunds.show.items')</h3>
            <table class="ph-table">
                <thead>
                    <tr>
                        <th>@lang('accounting.invoice.item')</th>
                        <th class="ac-num">@lang('accounting.common.qty')</th>
                        <th class="ac-num">@lang('accounting.common.unit_price')</th>
                        <th class="ac-num">@lang('accounting.common.total')</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($refund->items as $item)
                        <tr>
                            <td>{{ $item->saleItem->medicine_name ?? '—' }}</td>
                            <td class="ac-num">{{ $item->quantity }}</td>
                            <td class="ac-num">{{ account_money($item->saleItem->unit_price) }}</td>
                            <td class="ac-num">{{ account_money($item->amount) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if(!empty($refund->reason))
            <div class="ph-card">
                <h3 class="ph-card-title">@lang('accounting.refunds.show.reason')</h3>
                <p class="ac-muted">{{ $refund->reason }}</p>
            </div>
        @endif

        <div class="ph-card">
            <h3 class="ph-card-title">@lang('accounting.refunds.show.sale_summary')</h3>
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
            </div>
        </div>

        <div class="ph-actions">
            <a href="{{ route('pharmacy.accounting.refunds.index') }}" class="ph-btn outline">@lang('accounting.common.back')</a>
        </div>
    </div>
@endsection