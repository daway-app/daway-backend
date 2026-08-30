@extends('layouts.app')

@section('title', __('pharmacy.alternatives.index.title'))

@section('content')
    @vite(['resources/css/pages/pharmacy_hub.css', 'resources/js/pharmacy_hub.js'])
    @include('partials.pharmacy-hub-i18n')

    @php
        $needsAlternative = $pharmacyMedicines->filter(fn($pm) => $pm->quantity <= 0 && $pm->medicine->alternatives->isEmpty());
        $confirmDelete = __('pharmacy.alternatives.index.confirm_delete');
    @endphp

    <div class='ph-page'>
        <div class='ph-head'>
            <div class='ph-page-title'>
                <h1>@lang('pharmacy.alternatives.index.heading_page')</h1>
                <p>@lang('pharmacy.alternatives.index.subtitle')</p>
            </div>
        </div>

        @if (session('success'))
            <div class='ph-card' style='margin-block-end:20px;background:var(--ph-green-bg);color:var(--ph-green);border-color:var(--ph-green-bg);padding:14px 18px;'>{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class='ph-card' style='margin-block-end:20px;background:var(--ph-red-bg);color:var(--ph-red);border-color:var(--ph-red-bg);padding:14px 18px;'>{{ session('error') }}</div>
        @endif

        <div class='ph-stats'>
            <div class='ph-stat'><i class='fas fa-arrows-rotate teal'></i><div><strong>{{ $totalAlternatives }}</strong><span>@lang('pharmacy.alternatives.index.stat_defined')</span></div></div>
            <div class='ph-stat'><i class='fas fa-triangle-exclamation orange'></i><div><strong>{{ $needsAlternative->count() }}</strong><span>@lang('pharmacy.alternatives.index.stat_need')</span></div></div>
        </div>

        <div class='ph-filters'>
            <div class='ph-search' style='min-width:320px;'>
                <i class='fas fa-search'></i>
                <input type='text' placeholder='@lang('pharmacy.alternatives.index.search_placeholder')' data-ph-search='.ph-alt-block'>
            </div>
        </div>

        @forelse($pharmacyMedicines as $pm)
            @php
                $isOut = $pm->quantity <= 0;
                $currentAlts = $pm->medicine->alternatives;
                $candidates = $allMedicines->filter(fn($m) => $m->active_ingredient === $pm->medicine->active_ingredient && $m->id !== $pm->medicine->id);
            @endphp
            <div class='ph-card ph-alt-block' style='margin-block-end:20px;'>
                <div class='ph-card-head'><h2><i class='fas fa-pills'></i> {{ $pm->medicine->trade_name }}</h2></div>
                <div class='ph-card-body' style='display:grid;grid-template-columns:280px 1fr;gap:22px;'>
                    <div>
                        @if($isOut)
                            <div class='ph-badge out' style='margin-block-end:14px;'>@lang('pharmacy.alternatives.index.badge_unavailable')</div>
                        @endif
                        <div class='ph-alt-detail'>
                            <div class='row'><span>@lang('pharmacy.alternatives.index.detail_ingredient')</span><strong>{{ $pm->medicine->active_ingredient }}</strong></div>
                            <div class='row'><span>@lang('pharmacy.alternatives.index.detail_strength')</span><strong>{{ $pm->medicine->strength ?? '—' }}</strong></div>
                            <div class='row'><span>@lang('pharmacy.alternatives.index.detail_quantity')</span><strong style='color:{{ $isOut ? 'var(--ph-red)' : 'var(--ph-ink)' }}'>{{ $pm->quantity }}</strong></div>
                            <div class='row'><span>@lang('pharmacy.alternatives.index.detail_updated')</span><strong>{{ $pm->updated_at->format('Y-m-d h:i A') }}</strong></div>
                        </div>
                        @if($currentAlts->isEmpty())
                            <div class='ph-alt-notice'><i class='fas fa-circle-info'></i> @lang('pharmacy.alternatives.index.no_alternative_notice')</div>
                        @endif
                    </div>

                    <div class='ph-table-wrap'>
                        <table class='ph-table'>
                            <thead><tr><th>@lang('pharmacy.alternatives.index.col_medicine')</th><th>@lang('pharmacy.alternatives.index.col_ingredient')</th><th>@lang('pharmacy.alternatives.index.col_strength')</th><th>@lang('pharmacy.alternatives.index.col_quantity')</th><th>@lang('pharmacy.alternatives.index.col_price')</th><th>@lang('pharmacy.alternatives.index.col_actions')</th></tr></thead>
                            <tbody>
                                @forelse($candidates as $cand)
                                    @php $selected = $currentAlts->contains('id', $cand->id); @endphp
                                    <tr @if($selected) style="background:rgba(22,163,74,.06);" @endif>
                                        <td><strong>{{ $cand->trade_name }}</strong></td>
                                        <td>{{ $cand->active_ingredient }}</td>
                                        <td>{{ $cand->strength ?? '—' }}</td>
                                        <td><span class='ph-badge ok'>@lang('pharmacy.alternatives.index.available')</span></td>
                                        <td>{{ isset($cand->price) ? number_format($cand->price, 2).' '.trans('pharmacy.currency') : '—' }}</td>
                                        <td>
                                            @if($selected)
                                                <form action='{{ route('pharmacy.alternatives.destroy', ['pharmacyMedicine' => $pm->id, 'alternative' => $cand->id]) }}' method='POST' onsubmit='return confirm("{{ $confirmDelete }}");'>
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type='submit' class='ph-btn sm' style='background:var(--ph-green);color:#fff;border-color:var(--ph-green);'><i class='fas fa-check'></i> @lang('pharmacy.alternatives.index.selected')</button>
                                                </form>
                                            @else
                                                <form action='{{ route('pharmacy.alternatives.store') }}' method='POST'>
                                                    @csrf
                                                    <input type='hidden' name='base_medicine_id' value='{{ $pm->id }}'>
                                                    <input type='hidden' name='alternative_medicine_id' value='{{ $cand->id }}'>
                                                    <button type='submit' class='ph-btn sm outline'>@lang('pharmacy.alternatives.index.choose_alternative')</button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan='6'><div class='ph-empty' style='padding:24px;'><i class='fas fa-box-open'></i><h3>@lang('pharmacy.alternatives.index.no_candidates')</h3></div></td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                <div style='padding:14px 22px;border-block-start:1px solid var(--ph-line-soft);font-size:.8rem;color:var(--ph-ink-faint);'>
                    <i class='fas fa-circle-info'></i> @lang('pharmacy.alternatives.index.footer_note')
                </div>
            </div>
        @empty
            <div class='ph-empty'><i class='fas fa-box-open'></i><h3>@lang('pharmacy.alternatives.index.empty_medicines')</h3></div>
        @endforelse
    </div>
@endsection