<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/**
 * توثيق مسارات المريض العامة (تحت /api/patient/* و /api/profile/* وغيرها).
 * المصدر: app/Http/Controllers/Api/{Favorite,Cart,Order,Reminder,Notification,
 *          Address,MedicalProfile,PatientProfile,PatientInquiry,AvailabilityAlert,
 *          Coupon,Rating,DeviceToken}Controller.php
 *
 * كل هذه المسارات تتطلب مصادقة Sanctum (auth:sanctum).
 */
class PatientPaths
{
    /* ─────────────── الملف الشخصي ─────────────── */

    #[OA\Get(
        path: '/api/profile/patient',
        operationId: 'patientProfileShow',
        summary: 'عرض الملف الشخصي للمريض',
        tags: ['Patient Profile'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'بيانات الملف'),
            new OA\Response(response: 403, description: 'يتطلب دور patient'),
        ],
    )]
    public function patientProfileShow() {}

    #[OA\Post(
        path: '/api/profile/patient',
        operationId: 'patientProfileUpdate',
        summary: 'تحديث الملف الشخصي للمريض',
        description: 'يشمل الموقع والإعدادات الاختيارية.',
        tags: ['Patient Profile'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'تم التحديث'),
            new OA\Response(response: 422, description: 'بيانات غير صحيحة'),
        ],
    )]
    public function patientProfileUpdate() {}

    #[OA\Get(
        path: '/api/patient/health-profile',
        operationId: 'healthProfileShow',
        summary: 'عرض الملف الصحي',
        description: 'الأمراض المزمنة والحساسيات — مصدر لتخصيص التنبيهات (Guidance not diagnosis).',
        tags: ['Patient Profile'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'الملف الصحي')],
    )]
    public function healthProfileShow() {}

    #[OA\Put(
        path: '/api/patient/health-profile',
        operationId: 'healthProfileUpdate',
        summary: 'تحديث الملف الصحي',
        tags: ['Patient Profile'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
            new OA\Property(property: 'chronic_diseases', type: 'array', items: new OA\Items(type: 'string')),
            new OA\Property(property: 'allergies', type: 'array', items: new OA\Items(type: 'string')),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'تم التحديث'),
            new OA\Response(response: 422, description: 'بيانات غير صحيحة'),
        ],
    )]
    public function healthProfileUpdate() {}

    /* ─────────────── العناوين ─────────────── */

    #[OA\Get(
        path: '/api/patient/addresses',
        operationId: 'addressesIndex',
        summary: 'قائمة عناوين المريض',
        tags: ['Patient Addresses'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'قائمة العناوين')],
    )]
    public function addressesIndex() {}

    #[OA\Post(
        path: '/api/patient/addresses',
        operationId: 'addressesStore',
        summary: 'إضافة عنوان',
        tags: ['Patient Addresses'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 201, description: 'تمت الإضافة'),
            new OA\Response(response: 422, description: 'بيانات غير صحيحة'),
        ],
    )]
    public function addressesStore() {}

    #[OA\Get(
        path: '/api/patient/addresses/{address}',
        operationId: 'addressesShow',
        summary: 'عرض عنوان',
        tags: ['Patient Addresses'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'address', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'بيانات العنوان'),
            new OA\Response(response: 404, description: 'غير موجود'),
        ],
    )]
    public function addressesShow() {}

    #[OA\Put(
        path: '/api/patient/addresses/{address}',
        operationId: 'addressesUpdate',
        summary: 'تعديل عنوان',
        tags: ['Patient Addresses'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'address', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'تم التعديل'),
            new OA\Response(response: 422, description: 'بيانات غير صحيحة'),
        ],
    )]
    public function addressesUpdate() {}

    #[OA\Delete(
        path: '/api/patient/addresses/{address}',
        operationId: 'addressesDestroy',
        summary: 'حذف عنوان',
        tags: ['Patient Addresses'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'address', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'تم الحذف'),
            new OA\Response(response: 404, description: 'غير موجود'),
        ],
    )]
    public function addressesDestroy() {}

    /* ─────────────── المفضلة ─────────────── */

    #[OA\Get(
        path: '/api/patient/favorites/medicines',
        operationId: 'favoriteMedicinesIndex',
        summary: 'الأدوية المفضلة',
        description: 'يتضمّن `pharmacies_count` و`min_price` لحالة التوفر.',
        tags: ['Favorites'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'قائمة الأدوية المفضلة')],
    )]
    public function favoriteMedicinesIndex() {}

    #[OA\Post(
        path: '/api/patient/favorites/medicines/{medicine}',
        operationId: 'favoriteMedicinesStore',
        summary: 'إضافة دواء للمفضلة',
        tags: ['Favorites'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'medicine', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'تمت الإضافة'),
            new OA\Response(response: 404, description: 'الدواء غير موجود'),
        ],
    )]
    public function favoriteMedicinesStore() {}

    #[OA\Delete(
        path: '/api/patient/favorites/medicines/{medicine}',
        operationId: 'favoriteMedicinesDestroy',
        summary: 'إزالة دواء من المفضلة',
        tags: ['Favorites'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'medicine', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'تمت الإزالة')],
    )]
    public function favoriteMedicinesDestroy() {}

    #[OA\Get(
        path: '/api/patient/favorites/pharmacies',
        operationId: 'favoritePharmaciesIndex',
        summary: 'الصيدليات المفضلة',
        tags: ['Favorites'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'قائمة الصيدليات المفضلة')],
    )]
    public function favoritePharmaciesIndex() {}

    #[OA\Post(
        path: '/api/patient/favorites/pharmacies/{pharmacy}',
        operationId: 'favoritePharmaciesStore',
        summary: 'إضافة صيدلية للمفضلة',
        tags: ['Favorites'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'pharmacy', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'تمت الإضافة')],
    )]
    public function favoritePharmaciesStore() {}

    #[OA\Delete(
        path: '/api/patient/favorites/pharmacies/{pharmacy}',
        operationId: 'favoritePharmaciesDestroy',
        summary: 'إزالة صيدلية من المفضلة',
        tags: ['Favorites'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'pharmacy', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'تمت الإزالة')],
    )]
    public function favoritePharmaciesDestroy() {}

    /* ─────────────── السلة ─────────────── */

    #[OA\Get(
        path: '/api/patient/cart',
        operationId: 'cartShow',
        summary: 'عرض السلة',
        tags: ['Cart'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'محتويات السلة')],
    )]
    public function cartShow() {}

    #[OA\Post(
        path: '/api/patient/cart/items',
        operationId: 'cartStore',
        summary: 'إضافة عنصر للسلة',
        tags: ['Cart'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'تمت الإضافة'),
            new OA\Response(response: 422, description: 'بيانات غير صحيحة'),
        ],
    )]
    public function cartStore() {}

    #[OA\Put(
        path: '/api/patient/cart/items/{item}',
        operationId: 'cartUpdate',
        summary: 'تعديل كمية عنصر',
        tags: ['Cart'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'item', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'تم التعديل')],
    )]
    public function cartUpdate() {}

    #[OA\Delete(
        path: '/api/patient/cart/items/{item}',
        operationId: 'cartDestroy',
        summary: 'حذف عنصر من السلة',
        tags: ['Cart'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'item', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'تم الحذف')],
    )]
    public function cartDestroy() {}

    #[OA\Delete(
        path: '/api/patient/cart',
        operationId: 'cartClear',
        summary: 'تفريغ السلة',
        tags: ['Cart'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'تم التفريغ')],
    )]
    public function cartClear() {}

    /* ─────────────── الكوبونات والدفع ─────────────── */

    #[OA\Post(
        path: '/api/patient/coupons/validate',
        operationId: 'couponValidate',
        summary: 'التحقق من صلاحية كوبون',
        tags: ['Checkout'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['code'], properties: [
            new OA\Property(property: 'code', type: 'string'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'الكوبون صالح'),
            new OA\Response(response: 422, description: 'كوبون غير صالح'),
        ],
    )]
    public function couponValidate() {}

    #[OA\Post(
        path: '/api/patient/checkout',
        operationId: 'checkoutStore',
        summary: 'إتمام الطلب (Checkout)',
        tags: ['Checkout'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 201, description: 'تم إنشاء الطلب'),
            new OA\Response(response: 422, description: 'بيانات غير صحيحة / السلة فارغة'),
        ],
    )]
    public function checkoutStore() {}

    /* ─────────────── الطلبات ─────────────── */

    #[OA\Get(
        path: '/api/patient/orders',
        operationId: 'ordersIndex',
        summary: 'طلبات المريض',
        tags: ['Orders'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'قائمة الطلبات')],
    )]
    public function ordersIndex() {}

    #[OA\Get(
        path: '/api/patient/orders/{order}',
        operationId: 'ordersShow',
        summary: 'تفاصيل طلب',
        tags: ['Orders'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'تفاصيل الطلب'),
            new OA\Response(response: 404, description: 'غير موجود'),
        ],
    )]
    public function ordersShow() {}

    #[OA\Post(
        path: '/api/patient/orders/{order}/cancel',
        operationId: 'ordersCancel',
        summary: 'إلغاء طلب',
        tags: ['Orders'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'تم الإلغاء'),
            new OA\Response(response: 422, description: 'لا يمكن الإلغاء في هذه الحالة'),
        ],
    )]
    public function ordersCancel() {}

    #[OA\Get(
        path: '/api/patient/orders/{order}/tracking',
        operationId: 'ordersTracking',
        summary: 'تتبّع الطلب',
        tags: ['Orders'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'حالة التتبّع')],
    )]
    public function ordersTracking() {}

    /* ─────────────── الاستفسارات ─────────────── */

    #[OA\Get(
        path: '/api/patient/inquiries',
        operationId: 'patientInquiriesIndex',
        summary: 'استفسارات المريض',
        tags: ['Inquiries'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'قائمة الاستفسارات')],
    )]
    public function patientInquiriesIndex() {}

    #[OA\Post(
        path: '/api/patient/inquiries',
        operationId: 'patientInquiriesStore',
        summary: 'إنشاء استفسار/محادثة',
        description: '`pharmacy_id` إلزامي، `medicine_id` و`message` اختياريان — يسمح بالمحادثة المباشرة مع الصيدلية من الخريطة.',
        tags: ['Inquiries'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['pharmacy_id'],
            properties: [
                new OA\Property(property: 'pharmacy_id', type: 'integer', description: 'إلزامي'),
                new OA\Property(property: 'medicine_id', type: 'integer', nullable: true, description: 'اختياري'),
                new OA\Property(property: 'message', type: 'string', maxLength: 1000, nullable: true),
            ],
        )),
        responses: [
            new OA\Response(response: 201, description: 'تم إنشاء الاستفسار'),
            new OA\Response(response: 422, description: 'بيانات غير صحيحة'),
        ],
    )]
    public function patientInquiriesStore() {}

    #[OA\Get(
        path: '/api/patient/inquiries/{inquiry}/messages',
        operationId: 'patientInquiryMessages',
        summary: 'رسائل الاستفسار',
        tags: ['Inquiries'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'inquiry', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'قائمة الرسائل')],
    )]
    public function patientInquiryMessages() {}

    #[OA\Post(
        path: '/api/patient/inquiries/{inquiry}/messages',
        operationId: 'patientInquirySendMessage',
        summary: 'إرسال رسالة',
        tags: ['Inquiries'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'inquiry', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 201, description: 'تم الإرسال'),
            new OA\Response(response: 422, description: 'بيانات غير صحيحة'),
        ],
    )]
    public function patientInquirySendMessage() {}

    /* ─────────────── الأدوية (مخصّص للمريض) ─────────────── */

    #[OA\Get(
        path: '/api/patient/medicines/search',
        operationId: 'patientMedicinesSearch',
        summary: 'بحث أدوية (سياق المريض)',
        tags: ['Patient Medicines'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'نتائج البحث')],
    )]
    public function patientMedicinesSearch() {}

    #[OA\Get(
        path: '/api/patient/medicines/{medicine}',
        operationId: 'patientMedicinesShow',
        summary: 'تفاصيل دواء (سياق المريض)',
        tags: ['Patient Medicines'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'medicine', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'تفاصيل الدواء')],
    )]
    public function patientMedicinesShow() {}

    #[OA\Get(
        path: '/api/patient/medicines/{medicine}/alternatives',
        operationId: 'patientMedicinesAlternatives',
        summary: 'بدائل دواء (سياق المريض)',
        tags: ['Patient Medicines'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'medicine', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'قائمة البدائل')],
    )]
    public function patientMedicinesAlternatives() {}

    #[OA\Get(
        path: '/api/patient/medicines/{medicine}/availability',
        operationId: 'patientMedicinesAvailability',
        summary: 'توفّر دواء',
        tags: ['Patient Medicines'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'medicine', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'حالة التوفّر + pharmacies_count')],
    )]
    public function patientMedicinesAvailability() {}

    /* ─────────────── تنبيهات التوفر ─────────────── */

    #[OA\Get(
        path: '/api/patient/availability-alerts',
        operationId: 'availabilityAlertsIndex',
        summary: 'تنبيهات التوفر',
        tags: ['Availability Alerts'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'قائمة التنبيهات')],
    )]
    public function availabilityAlertsIndex() {}

    #[OA\Post(
        path: '/api/patient/availability-alerts',
        operationId: 'availabilityAlertsStore',
        summary: 'إنشاء تنبيه توفر',
        tags: ['Availability Alerts'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 201, description: 'تم الإنشاء'),
            new OA\Response(response: 422, description: 'بيانات غير صحيحة'),
        ],
    )]
    public function availabilityAlertsStore() {}

    #[OA\Delete(
        path: '/api/patient/availability-alerts/{alert}',
        operationId: 'availabilityAlertsDestroy',
        summary: 'حذف تنبيه توفر',
        tags: ['Availability Alerts'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'alert', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'تم الحذف')],
    )]
    public function availabilityAlertsDestroy() {}

    /* ─────────────── المساعد الذكي ─────────────── */

    #[OA\Post(
        path: '/api/patient/assistant/chat',
        operationId: 'patientAssistantChat',
        summary: 'محادثة المساعد الذكي',
        description: 'AI = Guidance not diagnosis. يمرّر الطلب إلى خدمة AI خارجية عبر DAWAY_AI_BASE_URL.',
        tags: ['Assistant'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
            new OA\Property(property: 'message', type: 'string'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'رد المساعد'),
            new OA\Response(response: 429, description: 'تجاوز حد المحاولات'),
        ],
    )]
    public function patientAssistantChat() {}

    /* ─────────────── التذكيرات ─────────────── */

    #[OA\Get(
        path: '/api/reminders',
        operationId: 'remindersIndex',
        summary: 'قائمة التذكيرات',
        tags: ['Reminders'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'قائمة التذكيرات')],
    )]
    public function remindersIndex() {}

    #[OA\Post(
        path: '/api/reminders',
        operationId: 'remindersStore',
        summary: 'إنشاء تذكير',
        tags: ['Reminders'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 201, description: 'تم الإنشاء'),
            new OA\Response(response: 422, description: 'بيانات غير صحيحة'),
        ],
    )]
    public function remindersStore() {}

    #[OA\Get(
        path: '/api/reminders/{reminder}',
        operationId: 'remindersShow',
        summary: 'عرض تذكير',
        tags: ['Reminders'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'reminder', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'بيانات التذكير')],
    )]
    public function remindersShow() {}

    #[OA\Put(
        path: '/api/reminders/{reminder}',
        operationId: 'remindersUpdate',
        summary: 'تعديل تذكير',
        tags: ['Reminders'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'reminder', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'تم التعديل')],
    )]
    public function remindersUpdate() {}

    #[OA\Delete(
        path: '/api/reminders/{reminder}',
        operationId: 'remindersDestroy',
        summary: 'حذف تذكير',
        tags: ['Reminders'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'reminder', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'تم الحذف')],
    )]
    public function remindersDestroy() {}

    #[OA\Post(
        path: '/api/reminders/{reminder}/taken',
        operationId: 'remindersMarkTaken',
        summary: 'تسجيل تناول الجرعة',
        tags: ['Reminders'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'reminder', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'تم التسجيل')],
    )]
    public function remindersMarkTaken() {}

    /* ─────────────── الإشعارات ─────────────── */

    #[OA\Get(
        path: '/api/notifications',
        operationId: 'notificationsIndex',
        summary: 'قائمة الإشعارات',
        tags: ['Notifications'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'قائمة الإشعارات')],
    )]
    public function notificationsIndex() {}

    #[OA\Get(
        path: '/api/notifications/count',
        operationId: 'notificationsCount',
        summary: 'عدد الإشعارات غير المقروءة',
        tags: ['Notifications'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'العدد')],
    )]
    public function notificationsCount() {}

    #[OA\Post(
        path: '/api/notifications/{notification}/read',
        operationId: 'notificationsMarkRead',
        summary: 'تحديد إشعار كمقروء',
        tags: ['Notifications'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'notification', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'تم التحديد')],
    )]
    public function notificationsMarkRead() {}

    #[OA\Post(
        path: '/api/notifications/mark-all-as-read',
        operationId: 'notificationsMarkAllRead',
        summary: 'تحديد كل الإشعارات كمقروءة',
        tags: ['Notifications'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'تم التحديد')],
    )]
    public function notificationsMarkAllRead() {}

    /* ─────────────── رموز الأجهزة ─────────────── */

    #[OA\Post(
        path: '/api/device-tokens',
        operationId: 'deviceTokensStore',
        summary: 'تسجيل رمز جهاز (FCM)',
        tags: ['Device Tokens'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'تم التسجيل'),
            new OA\Response(response: 422, description: 'بيانات غير صحيحة'),
        ],
    )]
    public function deviceTokensStore() {}

    #[OA\Delete(
        path: '/api/device-tokens/current',
        operationId: 'deviceTokensDestroyCurrent',
        summary: 'إلغاء تسجيل رمز الجهاز الحالي',
        tags: ['Device Tokens'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'تم الإلغاء')],
    )]
    public function deviceTokensDestroyCurrent() {}

    /* ─────────────── التقييمات ─────────────── */

    #[OA\Get(
        path: '/api/ratings',
        operationId: 'ratingsIndex',
        summary: 'قائمة التقييمات',
        tags: ['Ratings'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'قائمة التقييمات')],
    )]
    public function ratingsIndex() {}

    #[OA\Post(
        path: '/api/ratings',
        operationId: 'ratingsStore',
        summary: 'إنشاء تقييم',
        tags: ['Ratings'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 201, description: 'تم الإنشاء'),
            new OA\Response(response: 422, description: 'بيانات غير صحيحة'),
        ],
    )]
    public function ratingsStore() {}

    #[OA\Get(
        path: '/api/ratings/{rating}',
        operationId: 'ratingsShow',
        summary: 'عرض تقييم',
        tags: ['Ratings'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'rating', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'بيانات التقييم')],
    )]
    public function ratingsShow() {}

    #[OA\Put(
        path: '/api/ratings/{rating}',
        operationId: 'ratingsUpdate',
        summary: 'تعديل تقييم',
        tags: ['Ratings'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'rating', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'تم التعديل')],
    )]
    public function ratingsUpdate() {}

    #[OA\Delete(
        path: '/api/ratings/{rating}',
        operationId: 'ratingsDestroy',
        summary: 'حذف تقييم',
        tags: ['Ratings'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'rating', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'تم الحذف')],
    )]
    public function ratingsDestroy() {}

    /* ─────────────── OCR ─────────────── */

    #[OA\Post(
        path: '/api/ocr/medicine',
        operationId: 'ocrMedicine',
        summary: 'تعرّف على دواء من صورة',
        tags: ['OCR'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'نتيجة التعرّف'),
            new OA\Response(response: 429, description: 'تجاوز حد المحاولات'),
        ],
    )]
    public function ocrMedicine() {}
}
