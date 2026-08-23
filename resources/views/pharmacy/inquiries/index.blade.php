@extends('layouts.app')

@section('title', __('pharmacy.inquiries.title'))

@section('content')
    @vite(['resources/css/pages/pharmacy_hub.css', 'resources/js/pharmacy_hub.js'])
    @include('partials.pharmacy-hub-i18n')

    @php
        $statusText = fn($status) => $status === 'new' ? __('pharmacy.inquiries.status_new') : ($status === 'answered' ? __('pharmacy.inquiries.status_answered') : __('pharmacy.inquiries.status_closed'));
    @endphp

    <div class='ph-page'>
        <div class='ph-head'>
            <div class='ph-page-title'>
                <h1>@lang('pharmacy.inquiries.heading')</h1>
                <p>@lang('pharmacy.inquiries.subtitle')</p>
            </div>
        </div>

        @if (session('success'))
            <div class='ph-card' style='margin-block-end:20px;background:var(--ph-green-bg);color:var(--ph-green);border-color:var(--ph-green-bg);padding:14px 18px;'>{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class='ph-card' style='margin-block-end:20px;background:var(--ph-red-bg);color:var(--ph-red);border-color:var(--ph-red-bg);padding:14px 18px;'>{{ session('error') }}</div>
        @endif

        <div class='ph-stats'>
            <div class='ph-stat'><i class='fas fa-lock gray'></i><div><strong>{{ $closedCount }}</strong><span>@lang('pharmacy.inquiries.stat_closed')</span></div></div>
            <div class='ph-stat'><i class='fas fa-comment-dots blue'></i><div><strong>{{ $answeredCount }}</strong><span>@lang('pharmacy.inquiries.stat_answered')</span></div></div>
            <div class='ph-stat'><i class='fas fa-envelope green'></i><div><strong>{{ $newCount }}</strong><span>@lang('pharmacy.inquiries.stat_new')</span></div></div>
        </div>

        <div class='ph-filters'>
            <div class='ph-tabs' data-ph-tabs='.ph-inquiry-table'>
                <button class='ph-tab active' data-filter='all'>@lang('pharmacy.inquiries.filter_all')</button>
                <button class='ph-tab' data-filter='closed'>@lang('pharmacy.inquiries.filter_closed')</button>
                <button class='ph-tab' data-filter='ans'>@lang('pharmacy.inquiries.filter_answered')</button>
                <button class='ph-tab' data-filter='new'>@lang('pharmacy.inquiries.filter_new')</button>
            </div>
            <div class='ph-search'>
                <i class='fas fa-search'></i>
                <input type='text' placeholder='@lang('pharmacy.inquiries.search_placeholder')' data-ph-search='.ph-inquiry-table tbody tr'>
            </div>
        </div>

        <div class='ph-card ph-inquiry-table'>
            <div class='ph-card-body ph-table-wrap' style='padding:0;'>
                <table class='ph-table'>
                    <thead>
                        <tr>
                            <th>@lang('pharmacy.inquiries.col_patient')</th>
                            <th>@lang('pharmacy.inquiries.col_medicine')</th>
                            <th>@lang('pharmacy.inquiries.col_inquiry')</th>
                            <th>@lang('pharmacy.inquiries.col_date')</th>
                            <th>@lang('pharmacy.inquiries.col_status')</th>
                            <th>@lang('pharmacy.inquiries.col_action')</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($inquiries as $inquiry)
                            @php
                                $status = $inquiry->status ?? 'new';
                                $badgeClass = $status === 'new' ? 'new' : ($status === 'answered' ? 'ans' : 'closed');
                                $name = $inquiry->user->name ?? __('pharmacy.inquiries.patient_fallback');
                                $initials = mb_substr($name, 0, 2);
                            @endphp
                            <tr data-status='{{ $status }}'>
                                <td>
                                    <div style='display:flex;align-items:center;gap:10px;'>
                                        <span class='ph-avatar-sm'>{{ $initials }}</span>
                                        <strong>{{ $name }}</strong>
                                    </div>
                                </td>
                                <td>
                                    {{ $inquiry->medicine->trade_name ?? __('pharmacy.inquiries.medicine_fallback') }}
                                    @if($inquiry->medicine)
                                        <br><span class='ph-badge new' style='margin-block-start:4px;'>{{ $inquiry->medicine->strength ?? '' }}</span>
                                    @endif
                                </td>
                                <td style='max-width:280px;'>{{ $inquiry->message ?? __('pharmacy.inquiries.message_fallback') }}</td>
                                <td>{{ $inquiry->created_at->format('Y-m-d') }}<br><small style='color:var(--ph-ink-faint);'>{{ $inquiry->created_at->format('h:i A') }}</small></td>
                                <td><span class='ph-badge {{ $badgeClass }}'>{{ $statusText($status) }}</span></td>
                                <td>
                                    <div style='display:flex;gap:8px;'>
                                        @if($status === 'new')
                                            <form action='{{ route('pharmacy.inquiries.update', $inquiry) }}' method='POST'>
                                                @csrf
                                                @method('PUT')
                                                <input type='hidden' name='status' value='answered'>
                                                <button type='submit' class='ph-btn sm primary'>@lang('pharmacy.inquiries.answer_button')</button>
                                            </form>
                                            <form action='{{ route('pharmacy.inquiries.update', $inquiry) }}' method='POST'>
                                                @csrf
                                                @method('PUT')
                                                <input type='hidden' name='status' value='closed'>
                                                <button type='submit' class='ph-btn sm ghost'>@lang('pharmacy.inquiries.close_button')</button>
                                            </form>
                                        @elseif($status === 'answered')
                                            <form action='{{ route('pharmacy.inquiries.update', $inquiry) }}' method='POST'>
                                                @csrf
                                                @method('PUT')
                                                <input type='hidden' name='status' value='closed'>
                                                <button type='submit' class='ph-btn sm ghost'>@lang('pharmacy.inquiries.close_button')</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan='6'><div class='ph-empty'><i class='fas fa-inbox'></i><h3>@lang('pharmacy.inquiries.empty')</h3></div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($inquiries->hasPages())
                <div style='padding:18px 22px;border-block-start:1px solid var(--ph-line-soft);'>{{ $inquiries->links() }}</div>
            @endif
        </div>
    </div>
@endsection