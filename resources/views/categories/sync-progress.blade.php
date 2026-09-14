@extends('layouts.app')

@section('title', 'مزامنة الأقسام')

@section('content')
    <div class="top-header-bar">
        <div class="header-title-section">
            <h1>جارية مزامنة الأقسام…</h1>
            <p>يُعاد الطلب تلقائياً كل شريحة حتى الاكتمال — لا تغلق الصفحة.</p>
        </div>
    </div>

    @php
        $processed = $state['processed'] ?? 0;
        $total = max(1, $state['total'] ?? 1);
        $percent = min(100, (int) floor($processed / $total * 100));
    @endphp

    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:24px;margin-bottom:16px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
            <strong style="color:#0f172a;">{{ number_format($processed) }} / {{ number_format($state['total'] ?? 0) }} سجلاً ({{ $percent }}%)</strong>
            <a href="{{ route('categories.index') }}" style="color:#0ea5a4;text-decoration:none;font-size:14px;">توقف</a>
        </div>
        <div style="background:#e2e8f0;border-radius:999px;height:12px;overflow:hidden;">
            <div style="background:linear-gradient(90deg,#14b8a6,#0ea5a4);height:100%;width:{{ $percent }}%;border-radius:999px;"></div>
        </div>
        <div style="display:flex;gap:24px;margin-top:16px;color:#475569;font-size:14px;">
            <span>منشأة: {{ number_format($state['created'] ?? 0) }}</span>
            <span>محدثة: {{ number_format($state['updated'] ?? 0) }}</span>
            <span>تحت المراجعة: {{ number_format($state['needs_review'] ?? 0) }}</span>
        </div>
    </div>

    {{--
        الإرسال الذاتي بعد ثانيتين: كل شريحة تعالج ثم تستعدي POST جديد —
        متسلسل ولا يتجاوز حدود Render المجاني (قطع request بعد ~100 ثانية).
    --}}
    <form id="sync-continue" method="POST" action="{{ route('categories.sync') }}">
        @csrf
    </form>
    <script>setTimeout(function () { document.getElementById('sync-continue').submit(); }, 2000);</script>
@endsection
