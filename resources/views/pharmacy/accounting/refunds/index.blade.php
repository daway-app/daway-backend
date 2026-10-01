<?php /** @var \App\Models\Pharmacy $pharmacy */ ?>
<?php /** @var \Illuminate\Pagination\LengthAwarePaginator $refunds */ ?>
<?php /** @var bool $isDemo */ ?>
@extends('layouts.app')

@section('title', __('accounting.sidebar.refunds'))

@section('breadcrumb')
    <span aria-hidden="true">/</span>
    <a href="{{ route('pharmacy.accounting.overview') }}">{{ __('accounting.nav.breadcrumb_accounting') }}</a>
    <span aria-hidden="true">/</span>
    <span aria-current="page">{{ __('accounting.sidebar.refunds') }}</span>
@endsection

@section('content')
    @vite(['resources/css/pages/pharmacy_hub.css', 'resources/css/pages/pharmacy_accounting.css', 'resources/js/accounting/accounting-shared.js'])

    @php
        $canRefundSale = fn ($paid, $total) => $paid > 0 && bccomp((string) $paid, '0.00', 2) > 0;
    @endphp

    <div class="ph-page">
        @if($isDemo)
            <div class="ac-demo-banner ac-no-print" role="status">
                <i class="fas fa-flask" aria-hidden="true"></i>
                <span>@lang('accounting.common.mock_notice')</span>
            </div>
        @endif

        <div class="ph-page-title">
            <h1>@lang('accounting.sidebar.refunds')</h1>
            <p class="subtitle">@lang('accounting.refunds.subtitle')</p>
        </div>

        <div class="ph-filters ac-filters">
            <form method="GET" action="{{ route('pharmacy.accounting.refunds.index') }}">
                <input type="text" name="q" placeholder="@lang('accounting.common.search')" value="{{ request('q') }}" class="ph-input">

                <select name="status">
                    <option value="all" {{ request('status') === 'all' ? 'selected' : '' }}>@lang('accounting.common.all')</option>
                    <option value="completed" {{ request('status') === 'completed' ? 'selected' : '' }}>@lang('accounting.refunds.status.completed')</option>
                    <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>@lang('accounting.refunds.status.pending')</option>
                    <option value="cancelled" {{ request('status') === 'cancelled' ? 'selected' : '' }}>@lang('accounting.refunds.status.cancelled')</option>
                </select>

                <button type="submit" class="ph-btn outline">@lang('accounting.common.search')</button>
                @if(request('q') || request('status') !== 'all')
                    <a href="{{ route('pharmacy.accounting.refunds.index') }}" class="ph-btn">@lang('accounting.common.clear_filters')</a>
                @endif
            </form>
        </div>

        @if($refunds->isEmpty())
            <div class="ph-empty">
                <i class="fas fa-rotate-left" aria-hidden="true"></i>
                <h3>@lang('accounting.refunds.empty')</h3>
                <p>@lang('accounting.refunds.empty_desc')</p>
            </div>
        @else
            <table class="ph-table">
                <thead>
                    <tr>
                        <th>@lang('accounting.refunds.col_refund_id')</th>
                        <th>@lang('accounting.refunds.col_sale')</th>
                        <th>@lang('accounting.refunds.col_date')</th>
                        <th>@lang('accounting.refunds.col_amount')</th>
                        <th>@lang('accounting.refunds.col_status')</th>
                        <th>@lang('accounting.refunds.col_reason')</th>
                        <th>@lang('accounting.refunds.col_created_by')</th>
                        <th scope="col" class="ac-actions">@lang('accounting.common.actions')</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($refunds as $refund)
                        <tr>
                            <td>#{{ $refund->id }}</td>
                            <td>
                                <a href="{{ route('pharmacy.accounting.sales.show', $refund->sale->number) }}">
                                    {{ $refund->sale->number }}
                                </a>
                            </td>
                            <td>{{ $refund->refunded_at->format('Y-m-d') }}</td>
                            <td class="ac-num">{{ account_money($refund->amount) }}</td>
                            <td>
                                <span class="ph-badge {{ $refund->status === 'completed' ? 'refunded' : 'pending' }}">
                                    @lang('accounting.refunds.status.' . $refund->status)
                                </span>
                            </td>
                            <td class="ac-muted">{{ Str::limit($refund->reason, 50) }}</td>
                            <td class="ac-muted">{{ $refund->createdBy->name ?? '-' }}</td>
                            <td class="ac-actions">
                                <a href="{{ route('pharmacy.accounting.refunds.show', $refund->id) }}" class="ph-link" title="@lang('accounting.common.view')">
                                    <i class="far fa-eye" aria-hidden="true"></i>
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            {{ $refunds->links() }}
        @endif
    </div>
@endsection