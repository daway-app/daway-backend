@extends('layouts.app')

@section('title', __('pharmacy.ratings.title'))

@section('content')
    @vite(['resources/css/pages/medicines.css'])

    <div class="animated-page">
        <!-- 1. Top Header -->
        <div class="top-header-bar">
            <div class="header-title-section">
                <h1>@lang('pharmacy.ratings.heading', ['pharmacy' => $pharmacy->pharmacy_name])</h1>
                <p>@lang('pharmacy.ratings.avg_label', ['avg' => number_format($averageRating, 1)])</p>
            </div>
        </div>

        @if (session('success'))
            <div class="alert-message success">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="alert-message error">{{ session('error') }}</div>
        @endif

        <!-- 2. Ratings Card -->
        <div class="main-card">
            <div class="card-top-bar">
                <h3>@lang('pharmacy.ratings.title')</h3>
            </div>

            <div style="padding: 0 24px;">
                @forelse ($ratings as $rating)
                    <div style="padding: 16px 0; border-bottom: 1px solid #f1f5f9;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <strong style="font-size: 14px; color: #0f172a;">{{ $rating->user->name ?? __('pharmacy.ratings.anonymous_user') }}</strong>
                            <small style="color: #94a3b8; font-size: 12px;">{{ $rating->created_at->diffForHumans() }}</small>
                        </div>
                        <div class="rating-stars" style="margin-bottom: 8px; color: #e8a000;">
                            @for ($i = 1; $i <= 5; $i++)
                                <i class="{{ $i <= $rating->rating ? 'fas' : 'far' }} fa-star"></i>
                            @endfor
                        </div>
                        <p style="margin: 0; font-size: 13.5px; color: #334155;">{{ $rating->comment }}</p>
                    </div>
                @empty
                    <p style="text-align: center; padding: 30px; color: #94a3b8;">@lang('pharmacy.ratings.empty')</p>
                @endforelse
            </div>

            <div class="pagination-wrapper">
                {{ $ratings->links() }}
            </div>
        </div>
    </div>
@endsection