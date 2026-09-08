<?php

namespace App\Http\Controllers;

use App\Contracts\FcmSender;
use Illuminate\Http\JsonResponse;

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

        return response()->json([
            'status' => 'ok',
            'fcm' => $fcmConnected ? 'connected' : 'not-configured',
        ]);
    }
}