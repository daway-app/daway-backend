@extends('layouts.app')

@section('title', __('pharmacy.ratings.title'))

@section('content')
<div class="container-fluid">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">@lang('pharmacy.ratings.heading', ['pharmacy' => $pharmacy->pharmacy_name])</h1>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">@lang('pharmacy.ratings.avg_label', ['avg' => number_format($averageRating, 1)])</h6>
        </div>
        <div class="card-body">
            @forelse ($ratings as $rating)
                <div class="mb-3 p-3 border rounded">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">{{ $rating->user->name ?? __('pharmacy.ratings.anonymous_user') }}</h5>
                        <small class="text-muted">{{ $rating->created_at->diffForHumans() }}</small>
                    </div>
                    <div class="rating-stars mb-2">
                        @for ($i = 1; $i <= 5; $i++)
                            @if ($i <= $rating->rating)
                                <i class="fas fa-star text-warning"></i>
                            @else
                                <i class="far fa-star text-warning"></i>
                            @endif
                        @endfor
                    </div>
                    <p class="mb-0">{{ $rating->comment }}</p>
                </div>
            @empty
                <p class="text-center">@lang('pharmacy.ratings.empty')</p>
            @endforelse

            {{ $ratings->links() }}
        </div>
    </div>
</div>
@endsection
