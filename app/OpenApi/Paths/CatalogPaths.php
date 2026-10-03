<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/**
 * توثيق كتالوج الأدوية والأقسام (MedicineController · CategoryController · BarcodeLookupController).
 *
 * المصدر: app/Http/Controllers/Api/*.php + routes/api.php
 * ملاحظة: هذه المسارات عامة (بلا مصادقة) ما لم يُذكر خلاف ذلك.
 */
class CatalogPaths
{
    #[OA\Get(
        path: '/api/medicines',
        operationId: 'medicinesIndex',
        summary: 'قائمة الأدوية (كتالوج)',
        description: 'قائمة أدوية MOH مع فلترة اختيارية بالبحث/القسم/الشكل الدوائي. مرقّمة (paginated).',
        tags: ['Catalog'],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string'), description: 'بحث بالاسم التجاري/الجنيسي'),
            new OA\Parameter(name: 'category_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'dosage_form', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20)),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'قائمة الأدوية', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')),
                new OA\Property(property: 'pagination', type: 'object'),
            ])),
        ],
    )]
    public function medicinesIndex() {}

    #[OA\Get(
        path: '/api/medicines/{id}',
        operationId: 'medicinesShow',
        summary: 'تفاصيل دواء',
        tags: ['Catalog'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'تفاصيل الدواء'),
            new OA\Response(response: 404, description: 'غير موجود'),
        ],
    )]
    public function medicinesShow() {}

    #[OA\Get(
        path: '/api/medicines/search',
        operationId: 'medicinesSearch',
        summary: 'بحث سريع في الأدوية',
        tags: ['Catalog'],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'نتائج البحث'),
        ],
    )]
    public function medicinesSearch() {}

    #[OA\Post(
        path: '/api/medicines/resolve',
        operationId: 'medicinesResolve',
        summary: 'تحويل نص حر إلى دواء مطابق',
        description: 'يحل اسم دواء مكتوب يدويًا إلى معرّف في الكتالوج (MedicineResolver). يتطلب مصادقة.',
        tags: ['Catalog'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'query', type: 'string'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'نتيجة الحل'),
            new OA\Response(response: 401, description: 'غير مصادق'),
        ],
    )]
    public function medicinesResolve() {}

    #[OA\Get(
        path: '/api/medicines/active-ingredient/{ingredient}',
        operationId: 'medicinesByActiveIngredient',
        summary: 'أدوية حسب المادة الفعالة',
        tags: ['Catalog'],
        parameters: [
            new OA\Parameter(name: 'ingredient', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'قائمة الأدوية')],
    )]
    public function medicinesByActiveIngredient() {}

    #[OA\Get(
        path: '/api/medicines/{id}/pharmacies',
        operationId: 'medicinesPharmacies',
        summary: 'الصيدليات التي توفّر الدواء',
        tags: ['Catalog'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'قائمة الصيدليات مع السعر/الكمية')],
    )]
    public function medicinesPharmacies() {}

    #[OA\Get(
        path: '/api/moh-medicines/{moh}/pharmacies',
        operationId: 'mohMedicinesPharmacies',
        summary: 'صيدليات توفّر دواء MOH',
        tags: ['Catalog'],
        parameters: [
            new OA\Parameter(name: 'moh', in: 'path', required: true, schema: new OA\Schema(type: 'integer'), description: 'moh_medicines.id'),
        ],
        responses: [new OA\Response(response: 200, description: 'قائمة الصيدليات')],
    )]
    public function mohMedicinesPharmacies() {}

    #[OA\Get(
        path: '/api/medicines/barcode/{barcode}',
        operationId: 'medicinesBarcode',
        summary: 'البحث بالباركود',
        description: 'يبحث في medicine_barcodes ويعيد الدواء المحلي المرتبط.',
        tags: ['Catalog'],
        parameters: [
            new OA\Parameter(name: 'barcode', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'نتيجة الباركود'),
            new OA\Response(response: 404, description: 'لا يوجد دواء بهذا الباركود'),
        ],
    )]
    public function medicinesBarcode() {}

    #[OA\Get(
        path: '/api/categories',
        operationId: 'categoriesIndex',
        summary: 'قائمة الأقسام',
        tags: ['Categories'],
        responses: [new OA\Response(response: 200, description: 'الأقسام مع عدّادات الأدوية المتوفرة وصورها')],
    )]
    public function categoriesIndex() {}

    #[OA\Get(
        path: '/api/categories/{category}',
        operationId: 'categoriesShow',
        summary: 'تفاصيل قسم',
        description: 'يقبل المعرّف الرقمي أو الـslug.',
        tags: ['Categories'],
        parameters: [
            new OA\Parameter(name: 'category', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'تفاصيل القسم + الأقسام الفرعية'),
            new OA\Response(response: 404, description: 'قسم غير موجود'),
        ],
    )]
    public function categoriesShow() {}

    #[OA\Get(
        path: '/api/categories/{category}/medicines',
        operationId: 'categoriesMedicines',
        summary: 'أدوية القسم',
        description: 'أدوية القسم المتوفرة فعليًا (صيدلية نشطة + is_available + كمية>0). كل صف يتضمّن `image_url` و`pharmacies_count`.',
        tags: ['Categories'],
        parameters: [
            new OA\Parameter(name: 'category', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'subcategory_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'dosage_form', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'أدوية القسم مع صورة وعدد صيدليات لكل دواء'),
            new OA\Response(response: 404, description: 'قسم غير موجود'),
        ],
    )]
    public function categoriesMedicines() {}

    #[OA\Get(
        path: '/api/dosage-forms',
        operationId: 'dosageForms',
        summary: 'الأشكال الدوائية المعتمدة',
        tags: ['Categories'],
        responses: [new OA\Response(response: 200, description: 'قائمة الأشكال الدوائية القانونية')],
    )]
    public function dosageForms() {}

    #[OA\Get(
        path: '/api/medicine-filters',
        operationId: 'medicineFilters',
        summary: 'فلاتر البحث (أقسام/أشكال)',
        tags: ['Categories'],
        responses: [new OA\Response(response: 200, description: 'خيارات الفلترة')],
    )]
    public function medicineFilters() {}

    #[OA\Get(
        path: '/api/admin/categories/{category}/medicines',
        operationId: 'adminCategoriesMedicines',
        summary: 'أدوية القسم (إدارة — كتالوج كامل)',
        description: 'يتطلب دور admin. بلا فلتر التوفر — الكتالوج الكامل.',
        tags: ['Admin'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'category', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'كتالوج القسم الكامل'),
            new OA\Response(response: 403, description: 'يتطلب دور admin'),
        ],
    )]
    public function adminCategoriesMedicines() {}
}
