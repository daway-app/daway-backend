<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AvailabilityAlertController;
use App\Http\Controllers\Api\ChatAssistantController;
use App\Http\Controllers\Api\DeviceTokenController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\MedicalProfileController;
use App\Http\Controllers\Api\MedicineController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OcrController;
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

// AI Assistant + OCR (محمية — للمستخدمين المسجلين)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/chat', [ChatAssistantController::class, 'chat'])->middleware('throttle:30,1');
    Route::post('/ocr/medicine', [OcrController::class, 'identify'])->middleware('throttle:30,1');
});

// ✅ Routes Protected
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    // H4: rate-limit على تجديد الـ token (30 طلب/دقيقة) لمنع إطالة عمر token مسروق.
    Route::post('/refresh-token', [AuthController::class, 'refreshToken'])->middleware('throttle:30,1');

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

    // Patient-scoped routes (Phase 9 — SRS endpoints).
    // ملاحظة على ترتيب المسارات: `medicines/search` يجب أن يسبق `medicines/{medicine}`
    // وإلا فسيلتقط Laravel الـ wildcard أولاً ويفشل في مطابقة "search" كقيمة.
    Route::prefix('patient')->group(function () {
        Route::get('medicines/search', [MedicineController::class, 'search']);
        Route::get('medicines/{medicine}', [MedicineController::class, 'show']);
        // ملاحظة: `pharmacies` على MedicineController يعيد قائمة الصيدليات التي يتوفر بها الدواء —
        // وهذا نفس دلالياً معنى "availability" في SRS للمريض.
        Route::get('medicines/{medicine}/availability', [MedicineController::class, 'pharmacies']);
        Route::get('medicines/{medicine}/alternatives', [MedicineController::class, 'alternatives']);

        Route::get('favorites/medicines', [FavoriteController::class, 'medicines']);
        Route::post('favorites/medicines/{medicine}', [FavoriteController::class, 'storeMedicine']);
        Route::delete('favorites/medicines/{medicine}', [FavoriteController::class, 'destroyMedicine']);
        Route::get('favorites/pharmacies', [FavoriteController::class, 'pharmacies']);
        Route::post('favorites/pharmacies/{pharmacy}', [FavoriteController::class, 'storePharmacy']);
        Route::delete('favorites/pharmacies/{pharmacy}', [FavoriteController::class, 'destroyPharmacy']);

        Route::get('availability-alerts', [AvailabilityAlertController::class, 'index']);
        Route::post('availability-alerts', [AvailabilityAlertController::class, 'store']);
        Route::delete('availability-alerts/{alert}', [AvailabilityAlertController::class, 'destroy']);

        Route::get('health-profile', [MedicalProfileController::class, 'show']);
        Route::put('health-profile', [MedicalProfileController::class, 'update']);

        Route::post('assistant/analyze', [ChatAssistantController::class, 'analyze']);
    });

    // Device tokens (FCM) — خارج prefix('patient') لأن كلا الـ roles (patient/pharmacy) قد يسجّلان جهازاً.
    Route::post('device-tokens', [DeviceTokenController::class, 'store']);
    Route::delete('device-tokens/current', [DeviceTokenController::class, 'destroy']);

    // Pharmacy inquiries (role-checked)
    Route::middleware('role:pharmacy')->prefix('pharmacy')->group(function () {
        Route::get('inquiries', [PharmacyInquiryController::class, 'index']);
        Route::get('inquiries/{inquiry}', [PharmacyInquiryController::class, 'show']);
        Route::put('inquiries/{inquiry}', [PharmacyInquiryController::class, 'update']);
        Route::get('ratings', [PharmacyRatingController::class, 'index']);

        Route::get('medicines/search', [PharmacyMedicineController::class, 'search']);
        // إضافة دواء بالاسم مباشرة (للموبايل) — بدون medicine_id أو moh_medicine_id
        Route::post('medicines/by-name', [PharmacyMedicineController::class, 'storeByName']);
        Route::apiResource('medicines', PharmacyMedicineController::class)
            ->names('api.pharmacy.medicines');
        Route::get('medicines/{medicine}/alternatives', [PharmacyMedicineController::class, 'alternatives']);

        Route::get('inventory', [PharmacyInventoryController::class, 'index']);
        Route::put('inventory/{medicine}', [PharmacyInventoryController::class, 'update']);
        Route::post('inventory/bulk', [PharmacyInventoryController::class, 'bulkUpdate']);

        Route::get('alternatives', [PharmacyAlternativeController::class, 'index']);
        Route::post('alternatives', [PharmacyAlternativeController::class, 'store']);
        Route::delete('alternatives/{base}/{alternative}', [PharmacyAlternativeController::class, 'destroy']);

        Route::get('dashboard/stats', [PharmacyDashboardController::class, 'stats']);
        Route::post('change-password', [PharmacyProfileController::class, 'changePassword']);
    });

    // Ratings
    Route::apiResource('ratings', RatingController::class)->only(['index', 'store']);
});
