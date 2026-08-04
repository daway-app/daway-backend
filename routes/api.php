<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProfileController;

// مسار اختبار للتأكد أن الـ API يرجع JSON
Route::get('/test', function () {
    return response()->json(['message' => 'API is working!']);
});

// 1. مسارات عامة
Route::post('/login/patient', [AuthController::class, 'patientLogin']);
Route::post('/login/pharmacy', [AuthController::class, 'pharmacyLogin']);
Route::post('/otp/send', [AuthController::class, 'sendOtp']);
Route::post('/otp/verify', [AuthController::class, 'verifyOtp']);

// 2. مسارات محمية
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    // مسارات الملف الشخصي الجديدة
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::post('/profile/update', [ProfileController::class, 'update']);
});
