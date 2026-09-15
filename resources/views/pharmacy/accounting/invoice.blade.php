@extends('layouts.app')

@section('title', __('accounting.invoice.title'))

@section('breadcrumb')
    <span aria-hidden="true">/</span>
    <a href="{{ route('pharmacy.accounting.overview') }}">@lang('accounting.nav.breadcrumb_accounting')</a>
    <span aria-hidden="true">/</span>
    <a href="{{ route('pharmacy.accounting.sales.index') }}">@lang('accounting.sidebar.sales')</a>
    <span aria-hidden="true">/</span>
    <span aria-current="page">{{ $sale['number'] }}</span>
@endsection

@section('content')
    @vite(['resources/css/pages/pharmacy_hub.css', 'resources/css/pages/pharmacy_accounting.css', 'resources/js/accounting/accounting-shared.js', 'resources/js/accounting/accounting-invoice.js'])
    @include('partials.accounting-i18n')

    @php
        $CUR = \App\Support\Accounting\AccountingMockData::CURRENCY;
        $money = fn ($v) => \App\Support\Accounting\AccountingMockData::money((float) $v);

        $statusBadge = fn (string $s): string => match ($s) {
            'paid' => 'paid',
            'partially_paid' => 'partial',
            'unpaid' => 'unpaid',
            'refunded' => 'refunded',
            'cancelled' => 'cancelled',
            default => 'closed',
        };
    @endphp

    <div class="ph-page">
        @if($isDemo)
            <div class="ac-demo-banner ac-no-print" role="status">
                <i class="fas fa-flask" aria-hidden="true"></i>
                <span>@lang('accounting.common.mock_notice')</span>
            </div>
        @endif

        <div class="ph-head ac-no-print">
            <div class="ph-page-title">
                <h1>@lang('accounting.invoice.heading', ['number' => $sale['number']])</h1>
                <p>@lang('accounting.invoice.print_ready')</p>
            </div>
            <div class="ph-actions ac-invoice-actions">
                <button type="button" class="ph-btn primary" data-ac-print>
                    <i class="fas fa-print" aria-hidden="true"></i> @lang('accounting.common.print')
                </button>
                <button type="button" class="ph-btn outline" data-ac-coming-soon="#ac-invoice-msg">
                    <i class="fas fa-download" aria-hidden="true"></i> @lang('accounting.common.export')
                </button>
                @if($sale['remaining'] > 0)
                    <button type="button" class="ph-btn ghost" data-ac-coming-soon="#ac-invoice-msg">
                        <i class="fas fa-money-bill-wave" aria-hidden="true"></i> @lang('accounting.common.record_payment')
                    </button>
                @endif
                @if($sale['status'] !== 'refunded')
                    <button type="button" class="ph-btn danger" data-ac-coming-soon="#ac-invoice-msg">
                        <i class="fas fa-rotate-left" aria-hidden="true"></i> @lang('accounting.common.refund')
                    </button>
                @endif
            </div>
        </div>

        <div id="ac-invoice-msg" class="ac-inline-msg ac-no-print" style="margin-block-end:18px;"></div>

        <div class="ac-invoice-doc">
            {{-- رأس الفاتورة --}}
            <div class="ac-invoice-doc-head">
                <div>
                    <h2>{{ $sale['number'] }}</h2>
                    <span class="ac-muted">{{ $sale['date']->format('Y-m-d H:i') }}</span>
                </div>
                <span class="ph-badge {{ $statusBadge($sale['status']) }}">
                    @lang('accounting.statuses.' . $sale['status'])
                </span>
            </div>

            {{-- البيانات الوصفية --}}
            <dl class="ac-invoice-doc-meta">
                <div>
                    <dt>@lang('accounting.invoice.pharmacy')</dt>
                    <dd>{{ $pharmacy->pharmacy_name }}</dd>
                </div>
                <div>
                    <dt>@lang('accounting.invoice.customer')</dt>
                    <dd>{{ $sale['customer'] ?? __('accounting.sales.walk_in') }}</dd>
                </div>
                <div>
                    <dt>@lang('accounting.common.payment_method')</dt>
                    <dd>@lang('accounting.payment_methods.' . $sale['method'])</dd>
                </div>
                <div>
                    <dt>@lang('accounting.invoice.created_at')</dt>
                    <dd>{{ $sale['date']->format('Y-m-d H:i') }}</dd>
                </div>
                <div>
                    <dt>@lang('accounting.invoice.created_by')</dt>
                    <dd>{{ auth()->user()->name }}</dd>
                </div>
            </dl>

            {{-- الأصناف (ملخّص) --}}
            <div style="padding:18px 22px;border-block-end:1px solid var(--ac-line-soft);">
                <table class="ph-table">
                    <caption class="ac-hidden">@lang('accounting.invoice.items')</caption>
                    <thead>
                        <tr>
                            <th scope="col">@lang('accounting.invoice.item')</th>
                            <th scope="col" class="ac-num">@lang('accounting.common.qty')</th>
                            <th scope="col" class="ac-num">@lang('accounting.common.unit_price')</th>
                            <th scope="col" class="ac-num">@lang('accounting.common.total')</th>
                        </tr>
                    </thead>
                    <tbody>
                        {{-- ⚠️ بنود السطر غير متوفّرة في mock — نعرض العدد المتاح بمبلغ موزّع
                             افتراضي؟ لا: نعرض رسالة صريحة بدل اختلاق بنود وهمية بأسماء أدوية. --}}
                        <tr>
                            <td colspan="4">
                                <div class="ph-empty" style="padding:28px 16px;">
                                    <i class="fas fa-list-ul" aria-hidden="true"></i>
                                    <h3>@lang('accounting.invoice.items_pending')</h3>
                                    <p>@lang('accounting.invoice.items_pending_desc', ['count' => $sale['items']])</p>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            {{-- الإجماليات --}}
            <div class="ac-totals-block">
                <div class="ac-invoice-line">
                    <span class="label">@lang('accounting.common.subtotal')</span>
                    <span class="value">{{ $money($sale['subtotal']) }}</span>
                </div>
                <div class="ac-invoice-line">
                    <span class="label">@lang('accounting.common.discount')</span>
                    <span class="value {{ $sale['discount'] > 0 ? 'ac-neg' : '' }}">
                        {{ $sale['discount'] > 0 ? '−' : '' }}{{ $money($sale['discount']) }}
                    </span>
                </div>
                <div class="ac-invoice-line is-total">
                    <span class="label">@lang('accounting.common.total')</span>
                    <span class="value">{{ $money($sale['total']) }}</span>
                </div>
                <div class="ac-invoice-line">
                    <span class="label">@lang('accounting.common.paid')</span>
                    <span class="value">{{ $money($sale['paid']) }}</span>
                </div>
                <div class="ac-invoice-line is-remaining {{ $sale['remaining'] > 0 ? 'warn' : '' }}">
                    <span class="label">@lang('accounting.common.remaining')</span>
                    <span class="value">{{ $money($sale['remaining']) }}</span>
                </div>
            </div>

            {{-- سجل الدفعات --}}
            <div style="padding:18px 22px;border-block-start:1px solid var(--ac-line-soft);">
                <h3 style="margin:0 0 10px;font-size:.95rem;">
                    <i class="fas fa-clock-rotate-left" aria-hidden="true" style="color:var(--ac-teal-text);"></i>
                    @lang('accounting.invoice.payment_history')
                </h3>
                @if($sale['paid'] > 0)
                    <div class="ac-invoice-line">
                        <span class="label">
                            {{ $sale['date']->format('Y-m-d H:i') }} —
                            @lang('accounting.payment_methods.' . $sale['method'])
                        </span>
                        <span class="value">{{ $money($sale['paid']) }}</span>
                    </div>
                @else
                    <p class="ac-muted" style="margin:0;">@lang('accounting.invoice.no_payments')</p>
                @endif
            </div>
        </div>
    </div>
@endsection
