<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ReminderController;

// مسار اختبار
Route::get('/test', function () {
    return response()->json(['message' => 'API is working!']);
});

// ✅ Routes Public
Route::post('/otp/send', [AuthController::class, 'sendOtp']);
Route::post('/otp/verify', [AuthController::class, 'verifyOtp']);
Route::post('/login/patient', [AuthController::class, 'patientLogin']);
Route::post('/login/pharmacy', [AuthController::class, 'pharmacyLogin']);

// ✅ Routes Protected
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/refresh-token', [AuthController::class, 'refreshToken']);

    Route::get('/profile', [ProfileController::class, 'show']);
    Route::post('/profile/update', [ProfileController::class, 'update']);

    Route::apiResource('reminders', ReminderController::class);
    Route::post('/reminders/{reminder}/taken', [ReminderController::class, 'markTaken']);
});
