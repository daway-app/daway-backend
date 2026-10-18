<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 — شرائح رسوم التوصيل القابلة للتهيئة.
 *
 * كل صف هو شريحة مسافة لرسوم ثابتة:
 *   min_distance_meters .. max_distance_meters  (max = NULL تعني «بلا حد أعلى»)
 *
 * ⚠️ لا تُملأ أي أسعار تجارية في الترحيل أو أي Seeder. الشريحة الواحدة تبدأ
 *    `is_active = false` — فلا يمكن استخدامها في التسعير حتى يعتمدها الأدمن
 *    صراحةً ويفعّل «تسعير التوصيل» (مفتاح الإعداد `delivery_pricing_enabled`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_fee_tiers', function (Blueprint $table) {
            $table->id();
            // حدّ المسافة الأدنى (شامل) — بالمتر. غير سالب.
            $table->unsignedInteger('min_distance_meters');
            // حدّ المسافة الأعلى (شامل) — بالمتر. NULL = مفتوحة إلى ما لا نهاية.
            $table->unsignedInteger('max_distance_meters')->nullable();
            // الرسوم الثابتة لهذه الشريحة (بالشيقل) — غير سالبة.
            $table->decimal('fee', 10, 2);
            // هل الشريحة داخلة في مجموعة التسعير الفعّالة؟ تبدأ معطّلة.
            $table->boolean('is_active')->default(false);
            $table->timestamps();

            // الاستعلام الأساسي: جلب الشرائح الفعّالة مرتّبة بالمسافة.
            $table->index(['is_active', 'min_distance_meters'], 'delivery_fee_tiers_active_min_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_fee_tiers');
    }
};
