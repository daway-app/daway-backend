@extends('layouts.app')

@section('content')
<div class="space-y-6">
    <div>
        <h2 class="text-2xl font-bold text-gray-800">الإشراف على التقييمات</h2>
        <p class="text-sm text-gray-500">مراجعة تقييمات وتعليقات المستخدمين للصيدليات والمنتجات</p>
    </div>

    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm divide-y divide-gray-100">
        <div class="p-6 flex items-start justify-between gap-4">
            <div class="space-y-2">
                <div class="flex items-center gap-3">
                    <div class="font-bold text-gray-800">محمد علي</div>
                    <div class="flex text-amber-400 text-sm">
                        <i data-lucide="star" class="w-4 h-4 fill-current"></i>
                        <i data-lucide="star" class="w-4 h-4 fill-current"></i>
                        <i data-lucide="star" class="w-4 h-4 fill-current"></i>
                        <i data-lucide="star" class="w-4 h-4 fill-current"></i>
                        <i data-lucide="star" class="w-4 h-4 text-gray-300"></i>
                    </div>
                </div>
                <p class="text-sm text-gray-600">خدمة ممتازة والدواء متوفر دائماً بأسعار واضحة.</p>
                <div class="text-xs text-gray-400">تقييم لصيدلية: صيدلية الهدى - منذ يومين</div>
            </div>
            <button class="px-3 py-1.5 text-xs text-red-600 hover:bg-red-50 border border-red-200 rounded-lg font-semibold">إخفاء التقييم</button>
        </div>
    </div>
</div>
@endsection
