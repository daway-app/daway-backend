@extends('layouts.app')

@section('title', __('accounting.sales.title'))

@section('breadcrumb')
    <span aria-hidden="true">/</span>
    <a href="{{ route('pharmacy.accounting.overview') }}">@lang('accounting.nav.breadcrumb_accounting')</a>
    <span aria-hidden="true">/</span>
    <span aria-current="page">@lang('accounting.sidebar.sales')</span>
@endsection

@section('content')
    @vite(['resources/css/pages/pharmacy_hub.css', 'resources/css/pages/pharmacy_accounting.css', 'resources/js/accounting/accounting-shared.js', 'resources/js/accounting/accounting-sales.js'])
    @include('partials.accounting-i18n')

    @php
        $money = fn ($v) => \App\Support\Accounting\AccountingMockData::money((float) $v);

        $statusBadge = function (string $status): string {
            return match ($status) {
                'paid' => 'paid',
                'partially_paid' => 'partial',
                'unpaid' => 'unpaid',
                'refunded' => 'refunded',
                'cancelled' => 'cancelled',
                default => 'closed',
            };
        };

        $hasFilters = $q !== '' || $status !== 'all' || $method !== 'all' || $range !== 'all';

        $ranges = [
            'all' => __('accounting.common.all'),
            'today' => __('accounting.common.today'),
            '7d' => __('accounting.common.last_7_days'),
            '30d' => __('accounting.common.last_30_days'),
            'month' => __('accounting.common.this_month'),
        ];

        $methods = [
            'all' => __('accounting.common.all'),
            'cash' => __('accounting.payment_methods.cash'),
            'card' => __('accounting.payment_methods.card'),
            'bank_transfer' => __('accounting.payment_methods.bank_transfer'),
            'credit' => __('accounting.payment_methods.credit'),
        ];

        $statuses = [
            'all' => __('accounting.common.all'),
            'paid' => __('accounting.statuses.paid'),
            'partially_paid' => __('accounting.statuses.partially_paid'),
            'unpaid' => __('accounting.statuses.unpaid'),
            'refunded' => __('accounting.statuses.refunded'),
            'cancelled' => __('accounting.statuses.cancelled'),
        ];
    @endphp

    <div class="ph-page">
        @if($isDemo)
            <div class="ac-demo-banner" role="status">
                <i class="fas fa-flask" aria-hidden="true"></i>
                <span>@lang('accounting.common.mock_notice')</span>
            </div>
        @endif

        <div class="ph-head">
            <div class="ph-page-title">
                <h1>@lang('accounting.sales.heading')</h1>
                <p>@lang('accounting.sales.subtitle')</p>
            </div>
            <div class="ph-actions">
                <a href="{{ route('pharmacy.accounting.sales.create') }}" class="ph-btn primary">
                    <i class="fas fa-plus" aria-hidden="true"></i> @lang('accounting.sales.new_sale')
                </a>
            </div>
        </div>

        {{-- ===== شريط الفلاتر ===== --}}
        <form method="GET" action="{{ route('pharmacy.accounting.sales.index') }}" class="ac-filter-bar" data-ac-filters role="search">
            <div class="ac-filter-field ac-filter-grow">
                <label for="ac-q">@lang('accounting.common.search')</label>
                <div class="ph-search">
                    <i class="fas fa-search" aria-hidden="true"></i>
                    <input type="search" id="ac-q" name="q" value="{{ $q }}"
                           placeholder="@lang('accounting.sales.search_placeholder')" autocomplete="off">
                </div>
            </div>

            <div class="ac-filter-field">
                <label for="ac-range">@lang('accounting.sales.date_range')</label>
                <select id="ac-range" name="range" class="ph-select" data-ac-auto-submit>
                    @foreach($ranges as $key => $label)
                        <option value="{{ $key }}" @selected($range === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="ac-filter-field">
                <label for="ac-method">@lang('accounting.sales.filter_payment')</label>
                <select id="ac-method" name="method" class="ph-select" data-ac-auto-submit>
                    @foreach($methods as $key => $label)
                        <option value="{{ $key }}" @selected($method === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="ac-filter-field">
                <label for="ac-status">@lang('accounting.sales.filter_status')</label>
                <select id="ac-status" name="status" class="ph-select" data-ac-auto-submit>
                    @foreach($statuses as $key => $label)
                        <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="ac-filter-end">
                @if($hasFilters)
                    <a href="{{ route('pharmacy.accounting.sales.index') }}" class="ph-btn ghost sm">
                        <i class="fas fa-xmark" aria-hidden="true"></i> @lang('accounting.common.clear_filters')
                    </a>
                @endif
                <noscript>
                    <button type="submit" class="ph-btn outline sm">@lang('accounting.common.apply')</button>
                </noscript>
            </div>
        </form>

        {{-- ===== الجدول ===== --}}
        <div class="ph-card">
            <div class="ph-card-head">
                <h2><i class="fas fa-receipt" aria-hidden="true"></i> @lang('accounting.sales.heading')</h2>
                <p>@lang('accounting.common.results_count', ['count' => $summary['count']])</p>
            </div>

            @if($sales->isEmpty())
                <div class="ph-card-body">
                    @if($hasFilters)
                        <div class="ph-empty">
                            <i class="fas fa-magnifying-glass" aria-hidden="true"></i>
                            <h3>@lang('accounting.sales.no_results')</h3>
                            <p>@lang('accounting.sales.no_results_desc')</p>
                        </div>
                    @else
                        <div class="ph-empty">
                            <i class="fas fa-cash-register" aria-hidden="true"></i>
                            <h3>@lang('accounting.sales.empty')</h3>
                            <p>@lang('accounting.sales.empty_desc')</p>
                            <a href="{{ route('pharmacy.accounting.sales.create') }}" class="ph-btn primary" style="margin-block-start:16px;">
                                <i class="fas fa-plus" aria-hidden="true"></i> @lang('accounting.sales.new_sale')
                            </a>
                        </div>
                    @endif
                </div>
            @else
                <div class="ph-table-wrap">
                    <table class="ph-table">
                        <caption class="ac-hidden">@lang('accounting.sales.heading')</caption>
                        <thead>
                            <tr>
                                <th scope="col">@lang('accounting.sales.col_invoice')</th>
                                <th scope="col">@lang('accounting.sales.col_date')</th>
                                <th scope="col">@lang('accounting.sales.col_customer')</th>
                                <th scope="col">@lang('accounting.sales.col_items')</th>
                                <th scope="col" class="ac-num">@lang('accounting.sales.col_subtotal')</th>
                                <th scope="col" class="ac-num">@lang('accounting.sales.col_discount')</th>
                                <th scope="col" class="ac-num">@lang('accounting.sales.col_total')</th>
                                <th scope="col" class="ac-num">@lang('accounting.sales.col_paid')</th>
                                <th scope="col" class="ac-num">@lang('accounting.sales.col_remaining')</th>
                                <th scope="col">@lang('accounting.sales.col_payment')</th>
                                <th scope="col">@lang('accounting.sales.col_status')</th>
                                <th scope="col">@lang('accounting.sales.col_actions')</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($sales as $sale)
                                <tr>
                                    <td>
                                        <a href="{{ route('pharmacy.accounting.sales.show', $sale['number']) }}" class="ac-strong">
                                            {{ $sale['number'] }}
                                        </a>
                                    </td>
                                    <td>
                                        <div class="ac-cell-stack">
                                            <span>{{ $sale['date']->format('Y-m-d') }}</span>
                                            <small class="ac-muted">{{ $sale['date']->format('H:i') }}</small>
                                        </div>
                                    </td>
                                    <td>
                                        @if($sale['customer'])
                                            {{ $sale['customer'] }}
                                        @else
                                            <span class="ac-muted">@lang('accounting.sales.walk_in')</span>
                                        @endif
                                    </td>
                                    <td><span class="ac-muted">@lang('accounting.sales.items_count', ['count' => $sale['items']])</span></td>
                                    <td class="ac-num">{{ \App\Support\Accounting\AccountingMockData::money($sale['subtotal']) }}</td>
                                    <td class="ac-num">
                                        @if($sale['discount'] > 0)
                                            <span class="ac-neg">−{{ \App\Support\Accounting\AccountingMockData::money($sale['discount']) }}</span>
                                        @else
                                            <span class="ac-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="ac-num ac-strong">{{ \App\Support\Accounting\AccountingMockData::money($sale['total']) }}</td>
                                    <td class="ac-num">{{ \App\Support\Accounting\AccountingMockData::money($sale['paid']) }}</td>
                                    <td class="ac-num {{ $sale['remaining'] > 0 ? 'ac-neg ac-strong' : '' }}">
                                        {{ \App\Support\Accounting\AccountingMockData::money($sale['remaining']) }}
                                    </td>
                                    <td><span class="ac-muted">@lang('accounting.payment_methods.' . $sale['method'])</span></td>
                                    <td>
                                        <span class="ph-badge {{ $statusBadge($sale['status']) }}">
                                            @lang('accounting.statuses.' . $sale['status'])
                                        </span>
                                    </td>
                                    <td>
                                        <div class="ac-row-actions">
                                            <a href="{{ route('pharmacy.accounting.sales.show', $sale['number']) }}"
                                               class="ac-icon-btn" title="@lang('accounting.common.view')"
                                               aria-label="@lang('accounting.common.view') — {{ $sale['number'] }}">
                                                <i class="fas fa-eye" aria-hidden="true"></i>
                                            </a>
                                            <button type="button" class="ac-icon-btn" title="@lang('accounting.common.print')"
                                                    aria-label="@lang('accounting.common.print') — {{ $sale['number'] }}"
                                                    data-ac-print-row="{{ route('pharmacy.accounting.sales.show', $sale['number']) }}">
                                                <i class="fas fa-print" aria-hidden="true"></i>
                                            </button>
                                            <button type="button" class="ac-icon-btn" title="@lang('accounting.common.record_payment')"
                                                    aria-label="@lang('accounting.common.record_payment') — {{ $sale['number'] }}"
                                                    data-ac-coming-soon="#ac-sales-msg" @disabled($sale['remaining'] <= 0)>
                                                <i class="fas fa-money-bill-wave" aria-hidden="true"></i>
                                            </button>
                                            <button type="button" class="ac-icon-btn" title="@lang('accounting.common.refund')"
                                                    aria-label="@lang('accounting.common.refund') — {{ $sale['number'] }}"
                                                    data-ac-coming-soon="#ac-sales-msg" @disabled($sale['status'] === 'refunded')>
                                                <i class="fas fa-rotate-left" aria-hidden="true"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- ملخّص الفترة المعروضة --}}
                <div class="ac-table-summary">
                    <span>@lang('accounting.common.total'): <b>{{ $money($summary['total']) }}</b></span>
                    <span>@lang('accounting.common.paid'): <b>{{ $money($summary['paid']) }}</b></span>
                    <span>@lang('accounting.common.remaining'): <b class="{{ $summary['remaining'] > 0 ? 'ac-neg' : '' }}">{{ $money($summary['remaining']) }}</b></span>
                </div>

                <div id="ac-sales-msg" class="ac-inline-msg" style="margin:0 22px 18px;"></div>

                @if($pagination->hasPages())
                    <div style="padding:18px 22px;border-block-start:1px solid var(--ac-line-soft);">
                        {{ $pagination->links('partials.pagination') }}
                    </div>
                @endif
            @endif
        </div>
    </div>

    @push('scripts')
        <script>
            // طباعة صف: نفتح صفحة الفاتورة ثم نطبع (بدل اختلاق مسار طباعة)
            document.addEventListener('click', function (e) {
                var btn = e.target.closest('[data-ac-print-row]');
                if (!btn) return;
                window.open(btn.getAttribute('data-ac-print-row') + '?print=1', '_blank', 'noopener');
            });
        </script>
    @endpush
@endsection
