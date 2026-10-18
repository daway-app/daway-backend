<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 — لقطة رسوم التوصيل على مستوى الطلب الأب.
 *
 * قرار التصميم (موثّق في `outputs/accounting-linkage-design.md` §7.1):
 *   · طلب واحد ← عنوان واحد ← رحلة توصيل واحدة، فالرسوم قيمة **واحدة على الأب**.
 *   · `sub_orders.shipping_fee` يبقى 0 (محجوز) — لا رسوم مستقلة لكل صيدلية.
 *
 * العمود `default 0` فالطلبات التاريخية والمسار المعطّل للتسعير يبقون بصفر
 * بلا أي تغيير سلوكي.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'delivery_fee')) {
                $table->decimal('delivery_fee', 10, 2)->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'delivery_fee')) {
                $table->dropColumn('delivery_fee');
            }
        });
    }
};
