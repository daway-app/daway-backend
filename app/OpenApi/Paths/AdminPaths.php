<?php

namespace App\OpenApi\Paths;

use OpenApi\Attributes as OA;

/**
 * توثيق مسارات الإدارة (/api/admin/*).
 * المصدر: routes/api.php + app/Http/Controllers/Api/Admin*.php
 * كل المسارات تتطلب `auth:sanctum` + `role:admin`.
 */
class AdminPaths
{
    #[OA\Get(
        path: '/api/admin/maintenance/pharmacy-moh-backfill/dry-run',
        operationId: 'adminPharmacyMohBackfillDryRun',
        summary: 'تشخيص إعادة ربط pharmacy_medicines بـMOH (بلا كتابة)',
        description: 'يتطلب دور admin. تشغيل تشخيصي فقط — لا تعديل على البيانات.',
        tags: ['Admin'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'نتيجة التشخيص'),
            new OA\Response(response: 403, description: 'يتطلب دور admin'),
        ],
    )]
    public function adminPharmacyMohBackfillDryRun() {}
}
