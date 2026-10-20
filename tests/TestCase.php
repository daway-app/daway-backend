<?php

namespace Tests;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * تهيئة تسعير توصيل **برسوم صفر** بلا زرع أي شريحة: نضبط «السعر الأساسي»
     * البديل على 0 (ووضع `tiers` الافتراضي)، فيُطبَّق على أي مسافة لا تُطابق
     * شريحةً — فتكون رسوم كل الطلبات 0.
     *
     * لماذا لا نزرع شريحة [0, ∞) فعّالة؟ لأنها كانت **تحجب** أي شريحة يضيفها
     * اختبار لاحق (أول شريحة مطابقة تبدأ من min=0 تُختار دائمًا)، فيُخفي ذلك
     * سلوك الشرائح الحقيقي. بدون شرائح مزروعة تبقى شرائح الاختبار فعّالة بلا حجب.
     *
     * التسعير معطّل افتراضيًا ولم يعد يقبل الـcheckout؛ تستدعيها الاختبارات التي
     * لا تعنيها رسوم التوصيل لكنها تُنشئ طلبات عبر `/api/patient/checkout`،
     * ليبقى رفض «التسعير معطّل» واختبارات التسعير نفسها حصرية عليها.
     */
    protected function enableZeroFeeDeliveryPricing(): void
    {
        \App\Services\DeliveryPricingService::setBaseFee(0.0);
        \App\Services\DeliveryPricingService::setEnabled(true);
    }
}
