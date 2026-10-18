<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Phase 1 — رفض تسعير التوصيل لسببٍ قابل للعرض للمستخدم (إحداثيات ناقصة،
 * لا شريحة مطابقة، ...).
 *
 * الكنترولر يلتقطها ويحوّلها إلى خطأ تحقّق 422 **قبل** إنشاء الطلب أو خصم
 * المخزون، فلا طلب جزئي ولا خصم معلّق.
 */
class DeliveryPricingException extends RuntimeException
{
}
