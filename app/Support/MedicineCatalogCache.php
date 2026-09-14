<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * إبطال كاش كتالوج الأدوية.
 *
 * مُستخرج من Admin\MedicineController ليكون مصدراً واحداً: أي مسار يُنشئ
 * أو يعدّل أو يحذف دواءً يجب أن يناديه، وإلا يخدم الـ API العام قائمة قديمة
 * (دواء موجود فعلاً لكنه لا يظهر في البحث/القائمة).
 */
final class MedicineCatalogCache
{
    /**
     * إبطال قوائم الأدوية المخزّنة + رفع إصدار الكتالوج.
     */
    public static function bump(): void
    {
        Cache::forget('medicines_list_cache');
        Cache::forget('medicines_list_cache_v2');
        Cache::forget('medicines_list_cache_v3');

        Cache::add('med_medicines_version', 1, 3600 * 24 * 30);
        Cache::increment('med_medicines_version');
    }
}
