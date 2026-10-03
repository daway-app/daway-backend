<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/**
 * توثيق مسارات الصيدليات العامة (PharmacyController).
 * المصدر: app/Http/Controllers/Api/PharmacyController.php
 *
 * ⚠️ GET /api/pharmacies عام دائمًا (قاعدة المشروع) — لا مصادقة.
 */
class PharmacyPublicPaths
{
    #[OA\Get(
        path: '/api/pharmacies',
        operationId: 'pharmaciesIndex',
        summary: 'قائمة الصيدليات (عام)',
        description: 'بحث/فلترة الصيدليات القريبة. **عام دائمًا — لا يتطلب مصادقة.**',
        tags: ['Pharmacies'],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'region', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'lat', in: 'query', required: false, schema: new OA\Schema(type: 'number')),
            new OA\Parameter(name: 'lng', in: 'query', required: false, schema: new OA\Schema(type: 'number')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'قائمة الصيدليات'),
        ],
    )]
    public function pharmaciesIndex() {}

    #[OA\Get(
        path: '/api/pharmacies/{id}',
        operationId: 'pharmaciesShow',
        summary: 'تفاصيل صيدلية (عام)',
        tags: ['Pharmacies'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'تفاصيل الصيدلية'),
            new OA\Response(response: 404, description: 'غير موجودة'),
        ],
    )]
    public function pharmaciesShow() {}
}
