<?php

namespace App\Http\Controllers;

use App\Contracts\FcmSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function index(): JsonResponse
    {
        // مؤشر اتصال FCM: true فقط عندما FIREBASE_CREDENTIALS مضبوطة على السيرفر
        $fcmConnected = false;
        try {
            $fcmConnected = app(FcmSender::class)->enabled();
        } catch (\Throwable) {
            $fcmConnected = false;
        }

        // لمسة DB خفيفة — تحل محل `php artisan migrate:status` في حلقة
        // الإبقاء على الـ DB مستيقظاً (كانت تقلع Laravel كاملاً كل 4 دقائق
        // وتنافس عمال الـ server على ذاكرة 512MB → "تعلق" عند فتح الصفحات)
        $db = 'down';
        try {
            DB::select('SELECT 1');
            $db = 'up';
        } catch (\Throwable) {
            $db = 'down';
        }

        return response()->json([
            'status' => 'ok',
            'db' => $db,
            'fcm' => $fcmConnected ? 'connected' : 'not-configured',
        ]);
    }
}