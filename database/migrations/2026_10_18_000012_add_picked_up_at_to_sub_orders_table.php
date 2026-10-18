<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rider Delivery — تتبّع الاستلام لكل صيدلية على حدة.
 *
 * الطلب الأب لا يُعتبر «مستلَماً» إلا بعد استلام كل الطلبات الفرعية النشطة،
 * لذلك نحتاج لحظة استلام مستقلة لكل صيدلية. تبقى حالة الطلب الفرعي `ready`
 * (الاستلام حدث في طبقة التوصيل) — العمود هنا يسجّل الوقت فقط.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sub_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('sub_orders', 'picked_up_at')) {
                $table->timestamp('picked_up_at')->nullable()->after('ready_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sub_orders', function (Blueprint $table) {
            if (Schema::hasColumn('sub_orders', 'picked_up_at')) {
                $table->dropColumn('picked_up_at');
            }
        });
    }
};
