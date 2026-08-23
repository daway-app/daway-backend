@extends('layouts.app')

@section('title', __('pharmacy.ratings.title'))

@section('content')
    @vite(['resources/css/pages/pharmacy_hub.css', 'resources/js/pharmacy_hub.js'])
    @include('partials.pharmacy-hub-i18n')

    @push('scripts')
        <script src='https://cdn.jsdelivr.net/npm/chart.js'></script>
    @endpush

    @php
        $avg = number_format($averageRating ?? 0, 1);
        $total = $totalRatings ?? 0;
        $labels = json_encode($trendLabels ?? []);
        $data = json_encode($trendData ?? []);
        $unitLabel = fn($stars) => $stars == 1 ? __('pharmacy.ratings.star') : __('pharmacy.ratings.stars');
    @endphp

    <div class='ph-page'>
        <div class='ph-head'>
            <div class='ph-page-title'>
                <h1>@lang('pharmacy.ratings.heading_page')</h1>
                <p>@lang('pharmacy.ratings.subtitle', ['pharmacy' => $pharmacy->pharmacy_name])</p>
            </div>
        </div>

        <div style='display:grid;grid-template-columns:1fr 1fr 1.6fr;gap:20px;margin-block-end:20px;'>
            <div class='ph-card'>
                <div class='ph-card-body' style='text-align:center;'>
                    <div style='font-size:2.6rem;font-weight:700;color:var(--ph-ink);line-height:1;'>{{ $avg }} <span style='font-size:1.1rem;color:var(--ph-ink-faint);font-weight:600;'>@lang('pharmacy.ratings.out_of')</span></div>
                    <div class='ph-stars' style='font-size:1.3rem;margin-block:12px;'>
                        @for($i=1;$i<=5;$i++)<i class='{{ $i <= round($avg) ? 'fas' : 'far' }} fa-star'></i>@endfor
                    </div>
                    <p style='color:var(--ph-ink-faint);margin:0;'>@lang('pharmacy.ratings.ratings_count', ['count' => $total])</p>
                </div>
            </div>

            <div class='ph-card'>
                <div class='ph-card-head'><h2>@lang('pharmacy.ratings.distribution_title')</h2></div>
                <div class='ph-card-body'>
                    @foreach($distribution as $item)
                        <div style='display:flex;align-items:center;gap:10px;margin-block-end:10px;'>
                            <span style='width:56px;font-size:.85rem;color:var(--ph-ink-soft);'>{{ $item['stars'] }} {{ $unitLabel($item['stars']) }}</span>
                            <div style='flex:1;height:8px;background:var(--ph-canvas);border-radius:var(--ph-r-full);overflow:hidden;'>
                                <div style='width:{{ $item['percent'] }}%;height:100%;background:#F59E0B;border-radius:var(--ph-r-full);'></div>
                            </div>
                            <span style='width:70px;text-align:end;font-size:.8rem;color:var(--ph-ink-faint);'>{{ $item['count'] }} ({{ $item['percent'] }}%)</span>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class='ph-card'>
                <div class='ph-card-head'><h2>@lang('pharmacy.ratings.trend_title')</h2></div>
                <div class='ph-card-body'><div class='chart-box chart-sm'><canvas data-ph-chart='line' data-ph-labels='{{ $labels }}' data-ph-data='{{ $data }}'></canvas></div></div>
            </div>
        </div>

        <div class='ph-card'>
            <div class='ph-card-head'><h2><i class='fas fa-comment-dots'></i> @lang('pharmacy.ratings.latest_title')</h2></div>
            <div class='ph-card-body'>
                <div class='ph-grid'>
                    @forelse($ratings as $rating)
                        @php $name = $rating->user->name ?? __('pharmacy.ratings.patient_fallback'); @endphp
                        <div class='ph-rating-card'>
                            <div class='head'>
                                <div style='display:flex;align-items:center;gap:10px;'>
                                    <span class='ph-avatar-sm'>{{ mb_substr($name, 0, 2) }}</span>
                                    <strong>{{ $name }}</strong>
                                </div>
                                <span>{{ $rating->created_at->format('Y-m-d') }}</span>
                            </div>
                            <div class='ph-stars' style='margin-block-end:8px;'>
                                @for($i=1;$i<=5;$i++)<i class='{{ $i <= $rating->stars_rating ? 'fas' : 'far' }} fa-star'></i>@endfor
                            </div>
                            <p>{{ $rating->comment }}</p>
                        </div>
                    @empty
                        <div class='ph-empty' style='grid-column:1/-1;'><i class='far fa-comment-alt'></i><h3>@lang('pharmacy.ratings.empty_comments')</h3></div>
                    @endforelse
                </div>
            </div>
            @if($ratings->hasPages())
                <div style='padding:18px 22px;border-block-start:1px solid var(--ph-line-soft);'>{{ $ratings->links() }}</div>
            @endif
        </div>
    </div>
@endsection