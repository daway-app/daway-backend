@extends('layouts.app')

@section('title', 'استفسارات التوفر')

@section('content')
    @vite(['resources/css/pages/pharmacy_hub.css', 'resources/js/pharmacy_hub.js'])

    <div class='ph-page'>
        <div class='ph-head'>
            <div class='ph-page-title'>
                <h1>استفسارات المرضى</h1>
                <p>متابعة استفسارات توفر الأدوية</p>
            </div>
        </div>

        @if (session('success'))
            <div class='ph-card' style='margin-block-end:20px;background:var(--ph-green-bg);color:var(--ph-green);border-color:var(--ph-green-bg);padding:14px 18px;'>{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class='ph-card' style='margin-block-end:20px;background:var(--ph-red-bg);color:var(--ph-red);border-color:var(--ph-red-bg);padding:14px 18px;'>{{ session('error') }}</div>
        @endif

        <div class='ph-stats'>
            <div class='ph-stat'><i class='fas fa-lock gray'></i><div><strong>{{ $closedCount }}</strong><span>مغلقة</span></div></div>
            <div class='ph-stat'><i class='fas fa-comment-dots blue'></i><div><strong>{{ $answeredCount }}</strong><span>تم الرد</span></div></div>
            <div class='ph-stat'><i class='fas fa-envelope green'></i><div><strong>{{ $newCount }}</strong><span>جديدة</span></div></div>
        </div>

        <div class='ph-filters'>
            <div class='ph-tabs' data-ph-tabs='.ph-inquiry-table'>
                <button class='ph-tab active' data-filter='all'>الكل</button>
                <button class='ph-tab' data-filter='closed'>مغلقة</button>
                <button class='ph-tab' data-filter='ans'>تم الرد</button>
                <button class='ph-tab' data-filter='new'>جديدة</button>
            </div>
            <div class='ph-search'>
                <i class='fas fa-search'></i>
                <input type='text' placeholder='ابحث باسم المريض أو الدواء أو نص الاستفسار...' data-ph-search='.ph-inquiry-table tbody tr'>
            </div>
        </div>

        <div class='ph-card ph-inquiry-table'>
            <div class='ph-card-body ph-table-wrap' style='padding:0;'>
                <table class='ph-table'>
                    <thead>
                        <tr>
                            <th>المريض</th>
                            <th>الدواء</th>
                            <th>الاستفسار</th>
                            <th>التاريخ</th>
                            <th>الحالة</th>
                            <th>الإجراء</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($inquiries as $inquiry)
                            @php
                                $status = $inquiry->status ?? 'new';
                                $statusText = $status === 'new' ? 'جديدة' : ($status === 'answered' ? 'تم الرد' : 'مغلقة');
                                $badgeClass = $status === 'new' ? 'new' : ($status === 'answered' ? 'ans' : 'closed');
                                $name = $inquiry->user->name ?? 'مريض';
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
                                    {{ $inquiry->medicine->trade_name ?? 'دواء غير محدد' }}
                                    @if($inquiry->medicine)
                                        <br><span class='ph-badge new' style='margin-block-start:4px;'>{{ $inquiry->medicine->strength ?? '' }}</span>
                                    @endif
                                </td>
                                <td style='max-width:280px;'>{{ $inquiry->message ?? 'هل يتوفر هذا الدواء؟' }}</td>
                                <td>{{ $inquiry->created_at->format('Y-m-d') }}<br><small style='color:var(--ph-ink-faint);'>{{ $inquiry->created_at->format('h:i A') }}</small></td>
                                <td><span class='ph-badge {{ $badgeClass }}'>{{ $statusText }}</span></td>
                                <td>
                                    <div style='display:flex;gap:8px;'>
                                        @if($status === 'new')
                                            <form action='{{ route('pharmacy.inquiries.update', $inquiry) }}' method='POST'>
                                                @csrf
                                                @method('PUT')
                                                <input type='hidden' name='status' value='answered'>
                                                <button type='submit' class='ph-btn sm primary'>تم الرد</button>
                                            </form>
                                            <form action='{{ route('pharmacy.inquiries.update', $inquiry) }}' method='POST'>
                                                @csrf
                                                @method('PUT')
                                                <input type='hidden' name='status' value='closed'>
                                                <button type='submit' class='ph-btn sm ghost'>إغلاق</button>
                                            </form>
                                        @elseif($status === 'answered')
                                            <form action='{{ route('pharmacy.inquiries.update', $inquiry) }}' method='POST'>
                                                @csrf
                                                @method('PUT')
                                                <input type='hidden' name='status' value='closed'>
                                                <button type='submit' class='ph-btn sm ghost'>إغلاق</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan='6'><div class='ph-empty'><i class='fas fa-inbox'></i><h3>لا توجد استفسارات</h3></div></td></tr>
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
