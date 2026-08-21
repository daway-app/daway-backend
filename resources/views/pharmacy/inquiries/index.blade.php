@extends('layouts.app')

@section('title', 'استفسارات التوفر')

@section('content')
    @vite(['resources/css/pages/pharmacy_hub.css', 'resources/js/pharmacy_hub.js'])

    <div class='ph-page'>
        <div class='ph-head'>
            <div class='ph-page-title'>
                <h1>استفسارات التوفر</h1>
                <p>متابعة استفسارات المرضى حول توفر الأدوية</p>
            </div>
        </div>

        @if (session('success'))
            <div class='ph-card' style='margin-block-end:20px;background:var(--ph-green-bg);color:var(--ph-green);border-color:var(--ph-green-bg);padding:14px 18px;'>{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class='ph-card' style='margin-block-end:20px;background:var(--ph-red-bg);color:var(--ph-red);border-color:var(--ph-red-bg);padding:14px 18px;'>{{ session('error') }}</div>
        @endif

        <div class='ph-stats'>
            <div class='ph-stat'><i class='fas fa-envelope teal'></i><div><strong>{{ $newCount }}</strong><span>جديدة</span></div></div>
            <div class='ph-stat'><i class='fas fa-check-circle green'></i><div><strong>{{ $answeredCount }}</strong><span>تم الرد</span></div></div>
            <div class='ph-stat'><i class='fas fa-lock gray'></i><div><strong>{{ $closedCount }}</strong><span>مغلقة</span></div></div>
        </div>

        <div class='ph-tabs' data-ph-tabs='.ph-inquiry-list' style='margin-block-end:20px;'>
            <button class='ph-tab active' data-filter='all'>الكل</button>
            <button class='ph-tab' data-filter='new'>جديدة</button>
            <button class='ph-tab' data-filter='ans'>تم الرد</button>
            <button class='ph-tab' data-filter='closed'>مغلقة</button>
        </div>

        <div class='ph-card ph-inquiry-list'>
            <div class='ph-card-head'><h2><i class='fas fa-comments'></i> قائمة الاستفسارات</h2></div>
            <div class='ph-card-body'>
                @forelse($inquiries as $inquiry)
                    @php
                        $status = $inquiry->is_notified ? 'closed' : 'new';
                        $statusText = $status === 'new' ? 'جديدة' : 'مغلقة';
                    @endphp
                    <div class='ph-inquiry' data-status='{{ $status }}'>
                        <div class='ph-inquiry-info'>
                            <h4>{{ $inquiry->medicine->trade_name ?? 'دواء غير محدد' }}</h4>
                            <p>من: {{ $inquiry->user->name ?? 'مريض' }} — {{ $inquiry->created_at->diffForHumans() }}</p>
                        </div>
                        <div style='display:flex;align-items:center;gap:12px;'>
                            <span class='ph-badge {{ $status }}'>{{ $statusText }}</span>
                            @if($status === 'new')
                                <form action='{{ route('pharmacy.inquiries.update', $inquiry) }}' method='POST' style='display:inline;'>
                                    @csrf
                                    @method('PUT')
                                    <button type='submit' class='ph-btn sm primary'>تم الرد</button>
                                </form>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class='ph-empty'><i class='fas fa-inbox'></i><h3>لا توجد استفسارات</h3></div>
                @endforelse
            </div>
            @if($inquiries->hasPages())
                <div style='padding:18px 22px;border-block-start:1px solid var(--ph-line-soft);'>{{ $inquiries->links() }}</div>
            @endif
        </div>
    </div>
@endsection