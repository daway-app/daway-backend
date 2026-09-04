<?php

use App\Http\Controllers\HealthController;
// General Controllers
use App\Http\Controllers\web\Admin\DashboardController;
// Auth Controllers
use App\Http\Controllers\web\Admin\InventoryController;
// Admin Controllers
use App\Http\Controllers\web\Admin\LogController;
use App\Http\Controllers\web\Admin\MedicineController;
use App\Http\Controllers\web\Admin\NotificationController;
use App\Http\Controllers\web\Admin\CatalogImportController;
use App\Http\Controllers\web\Admin\SettingController;
use App\Http\Controllers\web\Admin\UserController;
use App\Http\Controllers\web\Auth\LoginController;
use App\Http\Controllers\web\General\LocaleController;
use App\Http\Controllers\web\General\ProfileController;
// Patient Controllers
use App\Http\Controllers\web\Patient\PatientController;
use App\Http\Controllers\web\Patient\PatientInquiryController;
// Pharmacy Controllers
use App\Http\Controllers\web\Pharmacy\PharmacyAlternativeController;
use App\Http\Controllers\web\Pharmacy\PharmacyController;
use App\Http\Controllers\web\Pharmacy\PharmacyDashboardController;
use App\Http\Controllers\web\Pharmacy\PharmacyInquiryController;
use App\Http\Controllers\web\Pharmacy\PharmacyInventoryController;
use App\Http\Controllers\web\Pharmacy\PharmacyMedicineController;
use App\Http\Controllers\web\Pharmacy\PharmacyProfileController;
use App\Http\Controllers\web\Pharmacy\PharmacyRatingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes - مسارات لوحة تحكم نظام دوائي
|--------------------------------------------------------------------------
*/

// ==================== HEALTH CHECK ====================

Route::get('/healthz', [HealthController::class, 'index']);

// ==================== PWA OFFLINE FALLBACK ====================

Route::view('/offline', 'offline')->name('offline');

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
    ])->middleware('throttle:login')->name('login');
});

// ==================== ADMIN ONLY ====================

Route::middleware(['auth', 'role:admin'])->group(function () {

    // ==================== DASHBOARD ====================

    Route::get('/', [
        DashboardController::class,
        'index',
    ])->name('dashboard');

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

    // ==================== SETTINGS ====================

    Route::get('/settings', [
        SettingController::class,
        'index',
    ])->name('settings.index');

    Route::post('/settings', [
        SettingController::class,
        'update',
    ])->name('settings.update');

    // ==================== CATALOG IMPORT ====================

    Route::post('/settings/catalog-import', [
        CatalogImportController::class,
        'import',
    ])->name('settings.catalog.import');

    // ==================== LOGS ====================

    Route::get('/logs', [
        LogController::class,
        'index',
    ])->name('logs.index');

    Route::get('/logs/export-excel', [
        LogController::class,
        'exportExcel',
    ])->name('logs.export.excel');
});

// ==================== PHARMACY ONLY ====================

Route::middleware(['auth', 'role:pharmacy'])->group(function () {

    // ==================== PHARMACY PROFILE COMPLETION (first login) ====================

    Route::get('/pharmacy/profile/complete', [
        \App\Http\Controllers\web\Pharmacy\PharmacyProfileCompletionController::class,
        'show',
    ])->name('pharmacy.profile.complete.show');

    Route::post('/pharmacy/profile/complete', [
        \App\Http\Controllers\web\Pharmacy\PharmacyProfileCompletionController::class,
        'store',
    ])->name('pharmacy.profile.complete');
});

Route::middleware(['auth', 'role:pharmacy', 'profile.complete'])->group(function () {

    // ==================== PHARMACY DASHBOARD ====================

    Route::get('/pharmacy/dashboard', [
        PharmacyDashboardController::class,
        'index',
    ])->name('pharmacy.dashboard.index');

    // ==================== PHARMACY INVENTORY ====================

    Route::get('/pharmacy/inventory', [
        PharmacyInventoryController::class,
        'index',
    ])->name('pharmacy.inventory.index');

    Route::put('/pharmacy/inventory', [
        PharmacyInventoryController::class,
        'update',
    ])->name('pharmacy.inventory.update');

    // ==================== PHARMACY INQUIRIES ====================

    Route::get('/pharmacy/inquiries', [
        PharmacyInquiryController::class,
        'index',
    ])->name('pharmacy.inquiries.index');

    Route::put('/pharmacy/inquiries/{inquiry}', [
        PharmacyInquiryController::class,
        'update',
    ])->name('pharmacy.inquiries.update');

    // ==================== PHARMACY MEDICINES ====================

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

    // ==================== PHARMACY ALTERNATIVES ====================

    // دعم pre-select من صفحة تعديل الدواء: route صريح بـ path param
    // {pharmacyMedicine?} لازم يسبق الـ resource لأن Laravel يُرجع أول
    // route بنفس الاسم. هذا يحلّ bug قديم كان يمرر الـ id في query string.
    Route::get('pharmacy/alternatives/create/{pharmacyMedicine?}', [
        PharmacyAlternativeController::class,
        'create',
    ])->name('pharmacy.alternatives.create');

    Route::resource(
        'pharmacy/alternatives',
        PharmacyAlternativeController::class
    )->only(['index', 'create', 'store'])
        ->names('pharmacy.alternatives');

    Route::delete('pharmacy/alternatives/{pharmacyMedicine}/{alternative}', [
        PharmacyAlternativeController::class,
        'destroy',
    ])->name('pharmacy.alternatives.destroy');

    // ==================== PHARMACY PROFILE ====================

    Route::get('/pharmacy/profile', [
        PharmacyProfileController::class,
        'edit',
    ])->name('pharmacy.profile.edit');

    Route::put('/pharmacy/profile', [
        PharmacyProfileController::class,
        'update',
    ])->name('pharmacy.profile.update');

    // ==================== PHARMACY RATINGS ====================

    Route::get('/pharmacy/ratings', [
        PharmacyRatingController::class,
        'index',
    ])->name('pharmacy.ratings.index');
});

// ==================== ANY AUTHENTICATED USER ====================

Route::middleware('auth')->group(function () {

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

    Route::put('/profile/password', [
        ProfileController::class,
        'updatePassword',
    ])->name('profile.password.update');

    // ==================== NOTIFICATIONS ====================

    Route::get('/notifications', [
        NotificationController::class,
        'showAll',
    ])->name('notifications.index');

    // ==================== LOGOUT ====================

    Route::post('/logout', [
        LoginController::class,
        'logout',
    ])->name('logout');

    // ==================== PATIENT INQUIRIES ====================

    Route::post('/patient/inquiries', [
        PatientInquiryController::class,
        'store',
    ])->name('patient.inquiries.store');
});
