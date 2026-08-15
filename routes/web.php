<?php

use App\Http\Controllers\web\Admin\DashboardController;
// General Controllers
use App\Http\Controllers\web\Admin\InventoryController;
// Auth Controllers
use App\Http\Controllers\web\Admin\LogController;
// Admin Controllers
use App\Http\Controllers\web\Admin\MedicineController;
use App\Http\Controllers\web\Admin\NotificationController;
use App\Http\Controllers\web\Admin\SettingController;
use App\Http\Controllers\web\Admin\UserController;
use App\Http\Controllers\web\Auth\LoginController;
use App\Http\Controllers\web\General\LocaleController;
use App\Http\Controllers\web\General\ProfileController;
use App\Http\Controllers\web\Patient\PatientController;
// Patient Controllers
use App\Http\Controllers\web\Pharmacy\PharmacyAlternativeController;
// Pharmacy Controllers
use App\Http\Controllers\web\Pharmacy\PharmacyController;
use App\Http\Controllers\web\Pharmacy\PharmacyDashboardController;
use App\Http\Controllers\web\Pharmacy\PharmacyMedicineController;
use App\Http\Controllers\web\Pharmacy\PharmacyProfileController;
use App\Http\Controllers\web\Pharmacy\PharmacyRatingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes - مسارات لوحة تحكم نظام دوائي
|--------------------------------------------------------------------------
*/

// ==================== LOCALE ====================

Route::get('locale/{locale}', [
    LocaleController::class,
    'changeLocale',
])->name('locale.change');

// ==================== AUTH ====================

Route::middleware('guest')->group(function () {

    Route::get('/login', [
        LoginController::class,
        'loginForm',
    ])->name('login.show');

    Route::post('/login', [
        LoginController::class,
        'login',
    ])->name('login');

    Route::get('/otp', [
        LoginController::class,
        'otpForm',
    ])->name('otp.verify');

    Route::post('/otp', [
        LoginController::class,
        'verifyOtp',
    ])->name('otp.verify.post');
});

// ==================== AUTHENTICATED ROUTES ====================

Route::middleware('auth')->group(function () {

    // ==================== DASHBOARD ====================

    Route::get('/', [
        DashboardController::class,
        'index',
    ])->name('dashboard');

    Route::get('/dashboard', [
        DashboardController::class,
        'index',
    ])->name('dashboard.index');

    // ==================== PHARMACY ====================

    // Pharmacy Dashboard
    Route::get('/pharmacy/dashboard', [
        PharmacyDashboardController::class,
        'index',
    ])->name('pharmacy.dashboard.index');

    // Pharmacy Medicines
    Route::get('/pharmacy/medicines/search', [
        PharmacyMedicineController::class,
        'search',
    ])->name('pharmacy.medicines.search');

    Route::resource(
        'pharmacy/medicines',
        PharmacyMedicineController::class
    )->except(['show'])
        ->parameters(['medicines' => 'pharmacyMedicine'])
        ->names('pharmacy.medicines');

    // Pharmacy Alternatives
    Route::resource(
        'pharmacy/alternatives',
        PharmacyAlternativeController::class
    )->only(['index', 'create', 'store'])
        ->names('pharmacy.alternatives');

    Route::delete('pharmacy/alternatives/{pharmacyMedicine}/{alternative}', [
        PharmacyAlternativeController::class,
        'destroy',
    ])->name('pharmacy.alternatives.destroy');

    // Pharmacy Profile
    Route::get('/pharmacy/profile', [
        PharmacyProfileController::class,
        'edit',
    ])->name('pharmacy.profile.edit');

    Route::put('/pharmacy/profile', [
        PharmacyProfileController::class,
        'update',
    ])->name('pharmacy.profile.update');

    // Pharmacy Ratings
    Route::get('/pharmacy/ratings', [
        PharmacyRatingController::class,
        'index',
    ])->name('pharmacy.ratings.index');

    // ==================== PHARMACIES ====================

    Route::resource(
        'pharmacies',
        PharmacyController::class
    );

    Route::patch('/pharmacies/{pharmacy}/toggle-status', [
        PharmacyController::class,
        'toggleStatus',
    ])->name('pharmacies.toggleStatus');

    // ==================== MEDICINES ====================

    Route::resource(
        'medicines',
        MedicineController::class
    );

    // ==================== USERS ====================

    Route::patch('/users/{user}/toggle-status', [
        UserController::class,
        'toggleStatus',
    ])->name('users.toggleStatus');

    Route::resource(
        'users',
        UserController::class
    );

    // ==================== PATIENTS ====================

    Route::get('/patients', [
        PatientController::class,
        'index',
    ])->name('patients.index');

    // ==================== INVENTORY ====================

    Route::get('/inventory', [
        InventoryController::class,
        'index',
    ])->name('inventory.index');

    // ==================== PROFILE ====================

    Route::get('/profile', [
        ProfileController::class,
        'edit',
    ])->name('profile.edit');

    Route::put('/profile', [
        ProfileController::class,
        'update',
    ])->name('profile.update');

    Route::post('/profile/update-ajax', [
        ProfileController::class,
        'updateAjax',
    ])->name('profile.update.ajax');

    // ==================== SETTINGS ====================

    Route::get('/settings', [
        SettingController::class,
        'index',
    ])->name('settings.index');

    Route::post('/settings', [
        SettingController::class,
        'update',
    ])->name('settings.update');

    // ==================== LOGS ====================

    Route::get('/logs', [
        LogController::class,
        'index',
    ])->name('logs.index');

    Route::get('/logs/export-excel', [
        LogController::class,
        'exportExcel',
    ])->name('logs.export.excel');

    // ==================== NOTIFICATIONS ====================

    Route::get('/notifications', [
        NotificationController::class,
        'showAll',
    ])->name('notifications.index');

    Route::prefix('api/notifications')->group(function () {
        Route::get('/count', [NotificationController::class, 'count'])
            ->name('notifications.count');
        Route::get('/', [NotificationController::class, 'index'])
            ->name('notifications.feed');
        Route::post('/mark-all-as-read', [NotificationController::class, 'markAllAsRead'])
            ->name('notifications.mark-all-as-read');
        Route::post('/{notification}/read', [NotificationController::class, 'markAsRead'])
            ->name('notifications.mark-as-read');
    });

    // ==================== LOGOUT ====================

    Route::post('/logout', [
        LoginController::class,
        'logout',
    ])->name('logout');
});
