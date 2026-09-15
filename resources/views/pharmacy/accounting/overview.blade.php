@extends('layouts.app')

@section('title', __('accounting.overview.title'))

@section('breadcrumb')
    <span aria-hidden="true">/</span>
    <a href="{{ route('pharmacy.accounting.overview') }}">@lang('accounting.nav.breadcrumb_accounting')</a>
    <span aria-hidden="true">/</span>
    <span aria-current="page">@lang('accounting.sidebar.overview')</span>
@endsection

@section('content')
    @vite(['resources/css/pages/pharmacy_hub.css', 'resources/css/pages/pharmacy_accounting.css', 'resources/js/accounting/accounting-shared.js', 'resources/js/accounting/accounting-overview.js'])
    @include('partials.accounting-i18n')

    @push('scripts')
        <script src="{{ asset('vendor/chart.umd.js') }}" defer></script>
    @endpush

    @php
        // بيانات الرسم تُمرَّر كمتغيّر واحد (@json على مصفوفة مبنيّة مسبقًا)
        $salesSeriesConfig = collect($salesSeries)->map(fn ($s) => [
            'labels' => $s['labels'],
            'data' => $s['data'],
        ])->all();

        $expenseLabels = array_column($expenseBreakdown, 'label');
        $expenseAmounts = array_column($expenseBreakdown, 'amount');
        $expenseChartColors = ['--info', '--teal-primary', '--warning', '--success', '--danger', '--teal-light', '--ink-faint'];

        $overviewConfig = [
            'defaultRange' => '7d',
            'salesSeries' => $salesSeriesConfig,
            'expenseLabels' => $expenseLabels,
            'expenseAmounts' => $expenseAmounts,
        ];

        // خريطة ألوان ثابتة للمصروفات — تدعم RTL/LTR وتعمل في الوضعين لأنها توكنات
        $expenseColorMap = [];
        foreach ($expenseLabels as $i => $label) {
            $expenseColorMap[$label] = $expenseChartColors[$i % count($expenseChartColors)];
        }
        $expenseColorsJson = json_encode(array_values($expenseColorMap));

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
    @endphp

    <div class="ph-page">
        {{-- شريط التنبيه: بيانات تجريبية --}}
        @if($isDemo)
            <div class="ac-demo-banner" role="status">
                <i class="fas fa-flask" aria-hidden="true"></i>
                <span>@lang('accounting.common.mock_notice')</span>
            </div>
        @endif

        <div class="ph-head">
            <div class="ph-page-title">
                <h1>@lang('accounting.overview.heading')</h1>
                <p>@lang('accounting.overview.subtitle', ['pharmacy' => $pharmacy->pharmacy_name])</p>
            </div>
            <div class="ph-actions">
                <a href="{{ route('pharmacy.accounting.sales.create') }}" class="ph-btn primary">
                    <i class="fas fa-cash-register" aria-hidden="true"></i> @lang('accounting.overview.new_sale')
                </a>
                <a href="{{ route('pharmacy.accounting.sales.index') }}" class="ph-btn outline">
                    <i class="fas fa-receipt" aria-hidden="true"></i> @lang('accounting.sidebar.sales')
                </a>
            </div>
        </div>

        {{-- ===== KPI Cards ===== --}}
        <div class="ac-kpi-grid">
            @foreach($kpis as $kpi)
                @php
                    $value = $kpi['format'] === 'money'
                        ? \App\Support\Accounting\AccountingMockData::money($kpi['value'])
                        : $kpi['value'];
                @endphp
                @if($kpi['href'])
                    <a href="{{ $kpi['href'] }}" class="ac-kpi">
                        <span class="ac-kpi-icon {{ $kpi['tone'] }}"><i class="{{ $kpi['icon'] }}" aria-hidden="true"></i></span>
                        <span class="ac-kpi-body">
                            <span class="ac-kpi-value">{{ $value }}</span>
                            <span class="ac-kpi-label">{{ $kpi['label'] }}</span>
                        </span>
                        <i class="fas fa-chevron-left ac-kpi-caret" aria-hidden="true"></i>
                    </a>
                @else
                    <div class="ac-kpi">
                        <span class="ac-kpi-icon {{ $kpi['tone'] }}"><i class="{{ $kpi['icon'] }}" aria-hidden="true"></i></span>
                        <span class="ac-kpi-body">
                            <span class="ac-kpi-value">{{ $value }}</span>
                            <span class="ac-kpi-label">{{ $kpi['label'] }}</span>
                        </span>
                    </div>
                @endif
            @endforeach
        </div>

        {{-- ===== الرسوم البيانية ===== --}}
        <div class="ac-grid ac-grid-2" style="margin-block-end:20px;">
            <div class="ph-card">
                <div class="ph-card-head">
                    <h2><i class="fas fa-chart-line" aria-hidden="true"></i> @lang('accounting.overview.chart_sales_title')</h2>
                    <p>@lang('accounting.overview.chart_sales_desc')</p>
                    <div class="ac-range-tabs" role="group" aria-label="@lang('accounting.overview.chart_sales_title')">
                        @foreach($salesRanges as $key => $label)
                            <button type="button"
                                    class="ac-range-tab {{ $key === '7d' ? 'active' : '' }}"
                                    data-ac-range="{{ $key }}"
                                    aria-pressed="{{ $key === '7d' ? 'true' : 'false' }}">{{ $label }}</button>
                        @endforeach
                    </div>
                </div>
                <div class="ph-card-body">
                    <div class="ac-chart-box">
                        <canvas data-ac-chart="sales" role="img" aria-label="@lang('accounting.overview.chart_sales_title')"></canvas>
                    </div>
                </div>
            </div>

            <div class="ph-card">
                <div class="ph-card-head">
                    <h2><i class="fas fa-chart-pie" aria-hidden="true"></i> @lang('accounting.overview.chart_expenses_title')</h2>
                    <p>@lang('accounting.overview.chart_expenses_desc')</p>
                </div>
                <div class="ph-card-body">
                    <div class="ac-chart-box" style="height:220px;">
                        <canvas data-ac-chart="expenses"
                                data-ac-labels='{{ json_encode($expenseLabels) }}'
                                data-ac-data='{{ json_encode($expenseAmounts) }}'
                                data-ac-colors='{{ $expenseColorsJson }}'
                                role="img" aria-label="@lang('accounting.overview.chart_expenses_title')"></canvas>
                    </div>

                    {{-- جدول نصّي بديل للرسم — مطلوب لـWCAG 1.1.1 (محتوى غير نصّي) --}}
                    <div class="ac-breakdown" style="margin-block-start:20px;">
                        @foreach($expenseBreakdown as $row)
                            @php $tone = $expenseColorMap[$row['label']] ?? '--ink-faint'; @endphp
                            <div class="ac-breakdown-row">
                                <div class="ac-breakdown-top">
                                    <span class="dot" style="background:var({{ $tone }})" aria-hidden="true"></span>
                                    <span class="name">{{ $row['label'] }}</span>
                                    <span class="amount">{{ \App\Support\Accounting\AccountingMockData::money($row['amount']) }}</span>
                                    <span class="pct">{{ $row['percentage'] }}%</span>
                                </div>
                                <div class="ac-bar" aria-hidden="true">
                                    <span style="width:{{ $row['percentage'] }}%;background:var({{ $tone }})"></span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        {{-- ===== آخر الحركات + التنبيهات ===== --}}
        <div class="ac-grid ac-grid-2">
            <div class="ph-card">
                <div class="ph-card-head">
                    <h2><i class="fas fa-clock-rotate-left" aria-hidden="true"></i> @lang('accounting.overview.recent_transactions')</h2>
                    <p>@lang('accounting.overview.recent_transactions_desc')</p>
                </div>
                <div class="ph-card-body ph-table-wrap" style="padding:0;">
                    @if(empty($transactions))
                        <div class="ph-empty">
                            <i class="fas fa-inbox" aria-hidden="true"></i>
                            <h3>@lang('accounting.overview.no_transactions')</h3>
                            <p>@lang('accounting.overview.no_transactions_desc')</p>
                        </div>
                    @else
                        <table class="ph-table">
                            <caption class="ac-hidden">@lang('accounting.overview.recent_transactions')</caption>
                            <thead>
                                <tr>
                                    <th scope="col">@lang('accounting.common.date')</th>
                                    <th scope="col">@lang('accounting.common.type')</th>
                                    <th scope="col">@lang('accounting.common.reference')</th>
                                    <th scope="col">@lang('accounting.common.description')</th>
                                    <th scope="col" class="ac-num">@lang('accounting.common.amount')</th>
                                    <th scope="col">@lang('accounting.common.payment_method')</th>
                                    <th scope="col">@lang('accounting.common.status')</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($transactions as $tx)
                                    <tr>
                                        <td>
                                            <div class="ac-cell-stack">
                                                <span>{{ $tx['date']->format('Y-m-d') }}</span>
                                                <small class="ac-muted">{{ $tx['time'] }}</small>
                                            </div>
                                        </td>
                                        <td><span class="ac-muted">@lang('accounting.payment_types.' . $tx['type'])</span></td>
                                        <td><span class="ac-mono">{{ $tx['reference'] }}</span></td>
                                        <td>{{ $tx['description'] }}</td>
                                        <td class="ac-num {{ $tx['amount'] < 0 ? 'ac-neg' : 'ac-pos' }}">
                                            {{ ($tx['amount'] < 0 ? '−' : '+') . \App\Support\Accounting\AccountingMockData::money(abs($tx['amount'])) }}
                                        </td>
                                        <td><span class="ac-muted">@lang('accounting.payment_methods.' . $tx['method'])</span></td>
                                        <td>
                                            <span class="ph-badge {{ $statusBadge($tx['status']) }}">
                                                @lang('accounting.statuses.' . $tx['status'])
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>

            <div class="ph-card">
                <div class="ph-card-head">
                    <h2><i class="fas fa-bell" aria-hidden="true"></i> @lang('accounting.overview.alerts_title')</h2>
                    <p>@lang('accounting.overview.alerts_desc')</p>
                </div>
                <div class="ph-card-body">
                    @if(empty($alerts))
                        <div class="ph-empty">
                            <i class="fas fa-circle-check" aria-hidden="true"></i>
                            <h3>@lang('accounting.overview.no_alerts')</h3>
                            <p>@lang('accounting.overview.no_alerts_desc')</p>
                        </div>
                    @else
                        @foreach($alerts as $alert)
                            <div class="ac-alert {{ $alert['severity'] }}">
                                <i class="{{ $alert['icon'] }}" aria-hidden="true"></i>
                                <div>
                                    <div class="ac-alert-title">{{ $alert['title'] }}</div>
                                    <div class="ac-alert-desc">{{ $alert['description'] }}</div>
                                </div>
                            </div>
                        @endforeach
                    @endif
                </div>
            </div>
        </div>
    </div>

    <script>
        window.acOverviewConfig = @json($overviewConfig);
    </script>
@endsection
