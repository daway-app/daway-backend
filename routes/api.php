<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MedicineController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PatientInquiryController;
use App\Http\Controllers\Api\PatientProfileController;
use App\Http\Controllers\Api\PharmacyAlternativeController;
use App\Http\Controllers\Api\PharmacyController;
use App\Http\Controllers\Api\PharmacyDashboardController;
use App\Http\Controllers\Api\PharmacyInquiryController;
use App\Http\Controllers\Api\PharmacyInventoryController;
use App\Http\Controllers\Api\PharmacyMedicineController;
use App\Http\Controllers\Api\PharmacyProfileController;
use App\Http\Controllers\Api\PharmacyRatingController;
use App\Http\Controllers\Api\RatingController;
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

    Route::get('/profile/patient', [PatientProfileController::class, 'show']);
    Route::post('/profile/patient', [PatientProfileController::class, 'update']);
    Route::get('/profile/pharmacy', [PharmacyProfileController::class, 'show']);
    Route::post('/profile/pharmacy', [PharmacyProfileController::class, 'update']);

    Route::apiResource('reminders', ReminderController::class);
    Route::post('/reminders/{reminder}/taken', [ReminderController::class, 'markTaken']);

    // Notifications
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/count', [NotificationController::class, 'count']);
    Route::post('notifications/mark-all-as-read', [NotificationController::class, 'markAllAsRead']);
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markAsRead']);

    // Patient inquiries
    Route::apiResource('patient/inquiries', PatientInquiryController::class)->only(['index', 'store']);

    // Pharmacy inquiries (role-checked)
    Route::middleware('role:pharmacy')->prefix('pharmacy')->group(function () {
        Route::get('inquiries', [PharmacyInquiryController::class, 'index']);
        Route::get('inquiries/{inquiry}', [PharmacyInquiryController::class, 'show']);
        Route::put('inquiries/{inquiry}', [PharmacyInquiryController::class, 'update']);
        Route::get('ratings', [PharmacyRatingController::class, 'index']);

        Route::get('medicines/search', [PharmacyMedicineController::class, 'search']);
        Route::apiResource('medicines', PharmacyMedicineController::class);
        Route::get('medicines/{medicine}/alternatives', [PharmacyMedicineController::class, 'alternatives']);

        Route::get('inventory', [PharmacyInventoryController::class, 'index']);
        Route::put('inventory/{medicine}', [PharmacyInventoryController::class, 'update']);
        Route::post('inventory/bulk', [PharmacyInventoryController::class, 'bulkUpdate']);

        Route::get('alternatives', [PharmacyAlternativeController::class, 'index']);
        Route::post('alternatives', [PharmacyAlternativeController::class, 'store']);
        Route::delete('alternatives/{base}/{alternative}', [PharmacyAlternativeController::class, 'destroy']);

        Route::get('dashboard/stats', [PharmacyDashboardController::class, 'stats']);
    });

    // Ratings
    Route::apiResource('ratings', RatingController::class)->only(['index', 'store']);
});
