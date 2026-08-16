<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MedicineController;
use App\Http\Controllers\Api\PharmacyController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ReminderController;
use Illuminate\Support\Facades\Route;

// ✅ Routes Public
Route::post('/otp/send', [AuthController::class, 'sendOtp'])->middleware('throttle:otp');
Route::post('/otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:otp-verify');
Route::post('/login/patient', [AuthController::class, 'patientLogin'])->middleware('throttle:login');
Route::post('/login/pharmacy', [AuthController::class, 'pharmacyLogin'])->middleware('throttle:login');

// Medicines Routes Public
Route::get('/medicines', [MedicineController::class, 'index']);
Route::get('/medicines/search', [MedicineController::class, 'search']);
Route::get('/medicines/active-ingredient/{ingredient}', [MedicineController::class, 'byActiveIngredient']);
Route::get('/medicines/{id}', [MedicineController::class, 'show']);
Route::get('/medicines/{id}/pharmacies', [MedicineController::class, 'pharmacies']);

// Pharmacies Routes Public
Route::get('/pharmacies', [PharmacyController::class, 'index']);
Route::get('/pharmacies/{id}', [PharmacyController::class, 'show']);

// ✅ Routes Protected
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/refresh-token', [AuthController::class, 'refreshToken']);

    Route::get('/profile', [ProfileController::class, 'show']);
    Route::post('/profile/update', [ProfileController::class, 'update']);

    Route::apiResource('reminders', ReminderController::class);
    Route::post('/reminders/{reminder}/taken', [ReminderController::class, 'markTaken']);
});
