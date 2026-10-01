<?php /** @var \App\Models\Pharmacy $pharmacy */ ?>
<?php /** @var \Illuminate\Pagination\LengthAwarePaginator $movements */ ?>
<?php /** @var string $type */ ?>
<?php /** @var string $direction */ ?>
<?php /** @var bool $isDemo */ ?>
@extends('layouts.app')

@section('title', __('accounting.sidebar.cash_register'))

@section('breadcrumb')
    <span aria-hidden="true">/</span>
    <a href="{{ route('pharmacy.accounting.overview') }}">{{ __('accounting.nav.breadcrumb_accounting') }}</a>
    <span aria-hidden="true">/</span>
    <span aria-current="page">{{ __('accounting.sidebar.cash_register') }}</span>
@endsection

@section('content')
    @vite(['resources/css/pages/pharmacy_hub.css', 'resources/css/pages/pharmacy_accounting.css', 'resources/js/accounting/accounting-shared.js'])

    <div class="ph-page">
        @if($isDemo)
            <div class="ac-demo-banner ac-no-print" role="status">
                <i class="fas fa-flask" aria-hidden="true"></i>
                <span>@lang('accounting.common.mock_notice')</span>
            </div>
        @endif

        <div class="ph-page-title">
            <h1>@lang('accounting.sidebar.cash_register')</h1>
            <p class="subtitle">@lang('accounting.cash_registers.subtitle')</p>
        </div>

        <div class="ph-filters ac-filters">
            <form method="GET" action="{{ route('pharmacy.accounting.cash.index') }}">
                <select name="type">
                    <option value="all">@lang('accounting.common.all')</option>
                    <option value="sale" {{ $type === 'sale' ? 'selected' : '' }}>@lang('accounting.payment_types.sale')</option>
                    <option value="refund" {{ $type === 'refund' ? 'selected' : '' }}>@lang('accounting.payment_types.sale')</option>
                    <option value="expense" {{ $type === 'expense' ? 'selected' : '' }}>@lang('accounting.payment_types.expense')</option>
                    <option value="supplier_payment" {{ $type === 'supplier_payment' ? 'selected' : '' }}>@lang('accounting.payment_types.supplier_payment')</option>
                    <option value="customer_payment" {{ $type === 'customer_payment' ? 'selected' : '' }}>@lang('accounting.payment_types.customer_payment')</option>
                    <option value="adjustment" {{ $type === 'adjustment' ? 'selected' : '' }}>@lang('accounting.payment_types.adjustment')</option>
                </select>

                <select name="direction">
                    <option value="all">@lang('accounting.common.all')</option>
                    <option value="incoming" {{ $direction === 'incoming' ? 'selected' : '' }}>@lang('accounting.cash_registers.incoming')</option>
                    <option value="outgoing" {{ $direction === 'outgoing' ? 'selected' : '' }}>@lang('accounting.cash_registers.outgoing')</option>
                </select>

                <button type="submit" class="ph-btn outline">@lang('accounting.common.apply')</button>
                @if($type !== 'all' || $direction !== 'all')
                    <a href="{{ route('pharmacy.accounting.cash.index') }}" class="ph-btn">@lang('accounting.common.clear_filters')</a>
                @endif
            </form>
        </div>

        @if($movements->isEmpty())
            <div class="ph-empty">
                <i class="fas fa-wallet" aria-hidden="true"></i>
                <h3>@lang('accounting.cash_registers.empty')</h3>
                <p>@lang('accounting.cash_registers.empty_desc')</p>
            </div>
        @else
            <table class="ph-table">
                <thead>
                    <tr>
                        <th>@lang('accounting.common.date')</th>
                        <th>@lang('accounting.payment_types.type')</th>
                        <th>@lang('accounting.common.direction')</th>
                        <th>@lang('accounting.common.amount')</th>
                        <th>@lang('accounting.common.description')</th>
                        <th>@lang('accounting.common.user')</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($movements as $movement)
                        <tr>
                            <td>{{ $movement->created_at->format('Y-m-d H:i') }}</td>
                            <td>@lang('accounting.cash_registers.types.' . $movement->type)</td>
                            <td>
                                <span class="ph-badge {{ $movement->direction === 'incoming' ? 'income' : 'expense' }}">
                                    @lang('accounting.cash_registers.direction.' . $movement->direction)
                                </span>
                            </td>
                            <td class="ac-num">{{ account_money($movement->amount) }}</td>
                            <td class="ac-muted">{{ $movement->description ?? '-' }}</td>
                            <td class="ac-muted">{{ $movement->user->name ?? '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            {{ $movements->links() }}
        @endif
    </div>
@endsection