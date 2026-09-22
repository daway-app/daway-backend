<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\PharmacyMohLinkDryRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * نقطة نهاية مؤقتة للمشرفين فقط — تشغيل Dry Run لربط
 * pharmacy_medicines بـ moh_medicines.
 *
 * ⚠️ هذه النقطة للقراءة فقط. لا تنفذ أي تعديل على البيانات.
 * تستخدم PharmacyMohLinkDryRun service الذي يعيد نفس المنطق
 * الخاص بـ BackfillPharmacyMohLinks --dry-run.
 */
class AdminPharmacyMohDryRunController extends Controller
{
    public function __invoke(Request $request, PharmacyMohLinkDryRun $service): JsonResponse
    {
        $result = $service->run();

        return response()->json([
            'success' => true,
            'message' => 'MOH pharmacy backfill dry run completed successfully.',
            'data' => $result,
        ]);
    }
}
