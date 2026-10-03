<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/**
 * توثيق مسارات الصيدلية المصادَق عليها (/api/pharmacy/*) + الملف الشخصي + التزامن.
 *
 * المصدر: app/Http/Controllers/Api/{PharmacyMedicine,PharmacyInventory,
 *          PharmacyInventoryImport,PharmacyAlternative,PharmacyDashboard,
 *          PharmacyProfile,PharmacyInquiry,PharmacyRating,MedicineRequest,
 *          PharmacyProfile,Order,Sync}Controller.php
 *
 * كل هذه المسارات: `auth:sanctum` + `role:pharmacy`.
 */
class PharmacyPaths
{
    /* ─────────────── إدارة الأدوية (PharmacyMedicineController) ─────────────── */

    #[OA\Get(
        path: '/api/pharmacy/medicines',
        operationId: 'pharmacyMedicinesIndex',
        summary: 'أدوية الصيدلية',
        tags: ['Pharmacy Medicines'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'قائمة أدوية الصيدلية'),
            new OA\Response(response: 403, description: 'يتطلب دور pharmacy'),
        ],
    )]
    public function pharmacyMedicinesIndex() {}

    #[OA\Post(
        path: '/api/pharmacy/medicines',
        operationId: 'pharmacyMedicinesStore',
        summary: 'إضافة دواء للصيدلية',
        description: 'يتطلب PharmacyMedicineRequest. حد الكتابة throttle:writes.',
        tags: ['Pharmacy Medicines'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 201, description: 'تمت الإضافة'),
            new OA\Response(response: 422, description: 'بيانات غير صحيحة'),
            new OA\Response(response: 429, description: 'تجاوز حد الكتابة'),
        ],
    )]
    public function pharmacyMedicinesStore() {}

    #[OA\Post(
        path: '/api/pharmacy/medicines/by-name',
        operationId: 'pharmacyMedicinesStoreByName',
        summary: 'إضافة دواء بالاسم مباشرة',
        description: 'بدون medicine_id أو moh_medicine_id — يُنشئ الدواء المحلي إذا لزم.',
        tags: ['Pharmacy Medicines'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'trade_name', type: 'string'),
            new OA\Property(property: 'price', type: 'number'),
            new OA\Property(property: 'quantity', type: 'integer'),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'تمت الإضافة'),
            new OA\Response(response: 422, description: 'بيانات غير صحيحة'),
        ],
    )]
    public function pharmacyMedicinesStoreByName() {}

    #[OA\Get(
        path: '/api/pharmacy/medicines/search',
        operationId: 'pharmacyMedicinesSearch',
        summary: 'بحث أدوية لإضافتها',
        tags: ['Pharmacy Medicines'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'نتائج البحث')],
    )]
    public function pharmacyMedicinesSearch() {}

    #[OA\Get(
        path: '/api/pharmacy/medicines/{medicine}',
        operationId: 'pharmacyMedicinesShow',
        summary: 'عرض دواء في الصيدلية',
        tags: ['Pharmacy Medicines'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'medicine', in: 'path', required: true, schema: new OA\Schema(type: 'integer'), description: 'pharmacy_medicines.id')],
        responses: [
            new OA\Response(response: 200, description: 'بيانات الدواء'),
            new OA\Response(response: 404, description: 'غير موجود'),
        ],
    )]
    public function pharmacyMedicinesShow() {}

    #[OA\Put(
        path: '/api/pharmacy/medicines/{medicine}',
        operationId: 'pharmacyMedicinesUpdate',
        summary: 'تعديل دواء في الصيدلية',
        tags: ['Pharmacy Medicines'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'medicine', in: 'path', required: true, schema: new OA\Schema(type: 'integer'), description: 'pharmacy_medicines.id')],
        responses: [
            new OA\Response(response: 200, description: 'تم التعديل'),
            new OA\Response(response: 422, description: 'بيانات غير صحيحة'),
        ],
    )]
    public function pharmacyMedicinesUpdate() {}

    #[OA\Delete(
        path: '/api/pharmacy/medicines/{medicine}',
        operationId: 'pharmacyMedicinesDestroy',
        summary: 'حذف دواء من الصيدلية',
        tags: ['Pharmacy Medicines'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'medicine', in: 'path', required: true, schema: new OA\Schema(type: 'integer'), description: 'pharmacy_medicines.id')],
        responses: [new OA\Response(response: 200, description: 'تم الحذف')],
    )]
    public function pharmacyMedicinesDestroy() {}

    #[OA\Get(
        path: '/api/pharmacy/medicines/{medicine}/alternatives',
        operationId: 'pharmacyMedicinesAlternatives',
        summary: 'بدائل دواء في الصيدلية',
        tags: ['Pharmacy Medicines'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'medicine', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'قائمة البدائل')],
    )]
    public function pharmacyMedicinesAlternatives() {}

    /* ─────────────── المخزون ─────────────── */

    #[OA\Get(
        path: '/api/pharmacy/inventory',
        operationId: 'pharmacyInventoryIndex',
        summary: 'قائمة المخزون',
        description: 'كل صف يتضمّن عدد صيدليات التوفّر. مرقّم.',
        tags: ['Pharmacy Inventory'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [new OA\Response(response: 200, description: 'قائمة المخزون')],
    )]
    public function pharmacyInventoryIndex() {}

    #[OA\Put(
        path: '/api/pharmacy/inventory/{medicine}',
        operationId: 'pharmacyInventoryUpdate',
        summary: 'تعديل كمية/سعر صنف مخزون',
        tags: ['Pharmacy Inventory'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'medicine', in: 'path', required: true, schema: new OA\Schema(type: 'integer'), description: 'pharmacy_medicines.id')],
        responses: [
            new OA\Response(response: 200, description: 'تم التعديل'),
            new OA\Response(response: 422, description: 'بيانات غير صحيحة'),
        ],
    )]
    public function pharmacyInventoryUpdate() {}

    #[OA\Post(
        path: '/api/pharmacy/inventory/bulk',
        operationId: 'pharmacyInventoryBulkUpdate',
        summary: 'تحديث مخزون جماعي',
        description: 'يتطلب PharmacyInventoryBulkRequest.',
        tags: ['Pharmacy Inventory'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'تم التحديث'),
            new OA\Response(response: 422, description: 'بيانات غير صحيحة'),
        ],
    )]
    public function pharmacyInventoryBulkUpdate() {}

    /* ─────────────── استيراد المخزون ─────────────── */

    #[OA\Get(
        path: '/api/pharmacy/inventory/import/template',
        operationId: 'inventoryImportTemplate',
        summary: 'تنزيل قالب الاستيراد',
        tags: ['Inventory Import'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'ملف القالب')],
    )]
    public function inventoryImportTemplate() {}

    #[OA\Post(
        path: '/api/pharmacy/inventory/import',
        operationId: 'inventoryImportPreview',
        summary: 'معاينة ملف الاستيراد',
        description: 'حد مخصّص throttle:inventory-import (لا يمسّ throttle:writes).',
        tags: ['Inventory Import'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'نتيجة المعاينة'),
            new OA\Response(response: 422, description: 'ملف غير صالح'),
        ],
    )]
    public function inventoryImportPreview() {}

    #[OA\Get(
        path: '/api/pharmacy/inventory/import/{import}',
        operationId: 'inventoryImportShow',
        summary: 'عرض عملية استيراد',
        tags: ['Inventory Import'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'import', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'تفاصيل العملية')],
    )]
    public function inventoryImportShow() {}

    #[OA\Get(
        path: '/api/pharmacy/inventory/import/{import}/errors',
        operationId: 'inventoryImportErrors',
        summary: 'أخطاء الاستيراد',
        tags: ['Inventory Import'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'import', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'قائمة الأخطاء')],
    )]
    public function inventoryImportErrors() {}

    #[OA\Post(
        path: '/api/pharmacy/inventory/import/{import}/decide',
        operationId: 'inventoryImportDecide',
        summary: 'تحديد قرار الاستيراد',
        tags: ['Inventory Import'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'import', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'تم التحديد')],
    )]
    public function inventoryImportDecide() {}

    #[OA\Post(
        path: '/api/pharmacy/inventory/import/{import}/commit',
        operationId: 'inventoryImportCommit',
        summary: 'تنفيذ الاستيراد',
        tags: ['Inventory Import'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'import', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'تم التنفيذ')],
    )]
    public function inventoryImportCommit() {}

    #[OA\Post(
        path: '/api/pharmacy/inventory/import/{import}/cancel',
        operationId: 'inventoryImportCancel',
        summary: 'إلغاء الاستيراد',
        tags: ['Inventory Import'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'import', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'تم الإلغاء')],
    )]
    public function inventoryImportCancel() {}

    /* ─────────────── البدائل ─────────────── */

    #[OA\Get(
        path: '/api/pharmacy/alternatives',
        operationId: 'pharmacyAlternativesIndex',
        summary: 'قائمة البدائل',
        tags: ['Pharmacy Alternatives'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'قائمة البدائل')],
    )]
    public function pharmacyAlternativesIndex() {}

    #[OA\Post(
        path: '/api/pharmacy/alternatives',
        operationId: 'pharmacyAlternativesStore',
        summary: 'إضافة بديل',
        tags: ['Pharmacy Alternatives'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 201, description: 'تمت الإضافة'),
            new OA\Response(response: 422, description: 'بيانات غير صحيحة'),
        ],
    )]
    public function pharmacyAlternativesStore() {}

    #[OA\Delete(
        path: '/api/pharmacy/alternatives/{base}/{alternative}',
        operationId: 'pharmacyAlternativesDestroy',
        summary: 'حذف بديل',
        tags: ['Pharmacy Alternatives'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'base', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'alternative', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [new OA\Response(response: 200, description: 'تم الحذف')],
    )]
    public function pharmacyAlternativesDestroy() {}

    /* ─────────────── لوحة التحكم والملف الشخصي ─────────────── */

    #[OA\Get(
        path: '/api/pharmacy/dashboard/stats',
        operationId: 'pharmacyDashboardStats',
        summary: 'إحصائيات لوحة تحكم الصيدلية',
        tags: ['Pharmacy Dashboard'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'إحصائيات المخزون والاستفسارات والتقييمات'),
            new OA\Response(response: 404, description: 'الصيدلية غير موجودة'),
        ],
    )]
    public function pharmacyDashboardStats() {}

    #[OA\Get(
        path: '/api/profile/pharmacy',
        operationId: 'pharmacyProfileShow',
        summary: 'عرض الملف الشخصي للصيدلية',
        tags: ['Pharmacy Profile'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'بيانات الملف + ساعات الدوام'),
            new OA\Response(response: 404, description: 'الصيدلية غير موجودة'),
        ],
    )]
    public function pharmacyProfileShow() {}

    #[OA\Post(
        path: '/api/profile/pharmacy',
        operationId: 'pharmacyProfileUpdate',
        summary: 'تحديث الملف الشخصي للصيدلية',
        description: 'يتطلب PharmacyProfileRequest (يشمل ساعات الدوام).',
        tags: ['Pharmacy Profile'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'تم التحديث'),
            new OA\Response(response: 422, description: 'بيانات غير صحيحة'),
        ],
    )]
    public function pharmacyProfileUpdate() {}

    #[OA\Post(
        path: '/api/pharmacy/change-password',
        operationId: 'pharmacyChangePassword',
        summary: 'تغيير كلمة مرور الصيدلية',
        description: 'اختياري: الطلب بلا حقول يُلغي إلزامية التغيير فقط. إرسال كلمة مرور يُبطل كل التوكنات (يعيد 401 ⇒ إعادة دخول).',
        tags: ['Pharmacy Profile'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
            new OA\Property(property: 'password', type: 'string', format: 'password', nullable: true),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'تم (قد يتطلب إعادة تسجيل الدخول)'),
            new OA\Response(response: 422, description: 'كلمة مرور غير صالحة'),
        ],
    )]
    public function pharmacyChangePassword() {}

    /* ─────────────── الاستفسارات والتقييمات ─────────────── */

    #[OA\Get(
        path: '/api/pharmacy/inquiries',
        operationId: 'pharmacyInquiriesIndex',
        summary: 'استفسارات الصيدلية',
        tags: ['Pharmacy Inquiries'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'قائمة الاستفسارات')],
    )]
    public function pharmacyInquiriesIndex() {}

    #[OA\Get(
        path: '/api/pharmacy/inquiries/{inquiry}',
        operationId: 'pharmacyInquiriesShow',
        summary: 'عرض استفسار',
        tags: ['Pharmacy Inquiries'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'inquiry', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'تفاصيل الاستفسار')],
    )]
    public function pharmacyInquiriesShow() {}

    #[OA\Put(
        path: '/api/pharmacy/inquiries/{inquiry}',
        operationId: 'pharmacyInquiriesUpdate',
        summary: 'تحديث حالة استفسار',
        description: 'يتطلب InquiryStatusRequest.',
        tags: ['Pharmacy Inquiries'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'inquiry', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'تم التحديث')],
    )]
    public function pharmacyInquiriesUpdate() {}

    #[OA\Get(
        path: '/api/pharmacy/inquiries/{inquiry}/messages',
        operationId: 'pharmacyInquiryMessages',
        summary: 'رسائل استفسار',
        tags: ['Pharmacy Inquiries'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'inquiry', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'قائمة الرسائل')],
    )]
    public function pharmacyInquiryMessages() {}

    #[OA\Post(
        path: '/api/pharmacy/inquiries/{inquiry}/messages',
        operationId: 'pharmacyInquirySendMessage',
        summary: 'إرسال رسالة من الصيدلية',
        tags: ['Pharmacy Inquiries'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'inquiry', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 201, description: 'تم الإرسال'),
            new OA\Response(response: 422, description: 'بيانات غير صحيحة'),
        ],
    )]
    public function pharmacyInquirySendMessage() {}

    #[OA\Get(
        path: '/api/pharmacy/ratings',
        operationId: 'pharmacyRatingsIndex',
        summary: 'تقييمات الصيدلية',
        tags: ['Pharmacy Ratings'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'قائمة التقييمات')],
    )]
    public function pharmacyRatingsIndex() {}

    /* ─────────────── طلبات الأدوية ─────────────── */

    #[OA\Get(
        path: '/api/pharmacy/medicine-requests',
        operationId: 'pharmacyMedicineRequestsIndex',
        summary: 'طلبات الأدوية غير المتوفرة',
        tags: ['Medicine Requests'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'قائمة الطلبات')],
    )]
    public function pharmacyMedicineRequestsIndex() {}

    #[OA\Post(
        path: '/api/pharmacy/medicine-requests',
        operationId: 'pharmacyMedicineRequestsStore',
        summary: 'إنشاء طلب دواء',
        tags: ['Medicine Requests'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 201, description: 'تم الإنشاء'),
            new OA\Response(response: 422, description: 'بيانات غير صحيحة'),
        ],
    )]
    public function pharmacyMedicineRequestsStore() {}

    /* ─────────────── حالة الطلبات ─────────────── */

    #[OA\Post(
        path: '/api/pharmacy/orders/{order}/status',
        operationId: 'pharmacyOrderUpdateStatus',
        summary: 'تحديث حالة طلب',
        tags: ['Pharmacy Orders'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'تم التحديث'),
            new OA\Response(response: 422, description: 'بيانات غير صحيحة'),
        ],
    )]
    public function pharmacyOrderUpdateStatus() {}

    /* ─────────────── التزامن (Offline) ─────────────── */

    #[OA\Post(
        path: '/api/sync/token',
        operationId: 'syncIssueToken',
        summary: 'إصدار توكن مزامنة',
        description: 'يتطلب مصادقة ويب (`auth`). يُستخدم لبدء جلسة مزامنة أوفلاين.',
        tags: ['Sync'],
        responses: [new OA\Response(response: 200, description: 'توكن المزامنة')],
    )]
    public function syncIssueToken() {}

    #[OA\Get(
        path: '/api/sync/pull',
        operationId: 'syncPull',
        summary: 'سحب بيانات المزامنة',
        tags: ['Sync'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'بيانات المزامنة')],
    )]
    public function syncPull() {}

    #[OA\Post(
        path: '/api/sync/push',
        operationId: 'syncPush',
        summary: 'دفع بيانات المزامنة',
        tags: ['Sync'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'نتيجة الدفع')],
    )]
    public function syncPush() {}
}
