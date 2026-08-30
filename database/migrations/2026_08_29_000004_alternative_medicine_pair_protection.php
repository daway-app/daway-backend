<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * H6: حماية جدول البدائل (alternative_medicine).
     *
     * المشكلتان:
     *  1) self-pair: دواء يُعلَّم كأنه بديل لنفسه (medicine_id = alternative_id).
     *  2) reverse duplicates: (A,B) و (B,A) معاً — يسبب تكراراً في نتائج
     *     Medicine::alternatives().
     *
     * الحل:
     *  - تنظيف البيانات الموجودة أولاً (حذف self-pairs والعكس المكرر).
     *  - MySQL: إضافة CHECK constraint يمنع self-pairs على مستوى الـ DB.
     *    (SQLite لا يدعم ADD CONSTRAINT — سيُفرض في الـ application layer
     *    عبر PharmacyAlternativeController).
     *  - فهرس فريد على (LEAST, GREATEST) غير مدعوم مباشرة عبر Blueprint،
     *    لذا التطبيع سيكون في الكود: يُخزَّن الزوج بترتيب ثابت (أصغر id أولاً)
     *    عند الكتابة، ويُمنع العكس بالفحص قبل الإدراج.
     */
    public function up(): void
    {
        // 1) حذف self-pairs الموجودة
        DB::table('alternative_medicine')
            ->whereColumn('medicine_id', 'alternative_id')
            ->delete();

        // 2) حذف الأزواج المعكوسة المكررة: نُبقي الصف ذا الـ id الأصغر
        $dupes = DB::table('alternative_medicine as a')
            ->join('alternative_medicine as b', function ($join) {
                $join->on('a.medicine_id', '=', 'b.alternative_id')
                    ->on('a.alternative_id', '=', 'b.medicine_id')
                    ->whereColumn('a.id', '<', 'b.id');
            })
            ->pluck('b.id');

        if ($dupes->isNotEmpty()) {
            DB::table('alternative_medicine')->whereIn('id', $dupes)->delete();
        }

        // 3) CHECK constraint على MySQL فقط
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE alternative_medicine ADD CONSTRAINT chk_alternative_not_self CHECK (medicine_id <> alternative_id)'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // احذف الـ CHECK إن وُجد (MySQL 8+: DROP CHECK)
            try {
                DB::statement('ALTER TABLE alternative_medicine DROP CHECK chk_alternative_not_self');
            } catch (\Throwable) {
                // الـ constraint غير موجود — تجاهل
            }
        }
    }
};
