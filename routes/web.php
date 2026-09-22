<?php

use App\Http\Controllers\HealthController;
// General Controllers
use App\Http\Controllers\web\Admin\DashboardController;
use App\Http\Controllers\web\Admin\CategoryController;
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
use App\Http\Controllers\web\Auth\RegisterController;
use App\Http\Controllers\web\General\LocaleController;
use App\Http\Controllers\web\General\ProfileController;
// Patient Controllers
use App\Http\Controllers\web\Patient\PatientController;
use App\Http\Controllers\web\Patient\PatientInquiryController;
// Pharmacy Controllers
use App\Http\Controllers\web\Pharmacy\AccountingController;
use App\Http\Controllers\web\Pharmacy\PharmacyAlternativeController;
use App\Http\Controllers\web\Pharmacy\PharmacyController;
use App\Http\Controllers\web\Pharmacy\PharmacyDashboardController;
use App\Http\Controllers\web\Pharmacy\PharmacyInquiryController;
use App\Http\Controllers\web\Pharmacy\PharmacyImportController;
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
    ])->middleware(['throttle:login', 'throttle:login-account'])->name('login');

    // ==================== PHARMACY SELF-REGISTRATION ====================
    // إنشاء حساب صيدلية (بانتظار موافقة الأدمن) — لا يظهر ID ولا كلمة مرور.
    // بيانات الدخول تُسلّم فور موافقة الأدمن (عبر SMS/OTP أو أي قناة).

    Route::get('/register', [
        RegisterController::class,
        'show',
    ])->name('register.show');

    Route::post('/register', [
        RegisterController::class,
        'store',
    ])->middleware('throttle:register')->name('register');
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

    // استرجاع بيانات دخول صيدلية فُقدت بياناتها — كلمة مرور جديدة تُسلَّم للأدمن مرة واحدة
    Route::patch('/pharmacies/{pharmacy}/reset-credentials', [
        PharmacyController::class,
        'resetCredentials',
    ])->name('pharmacies.resetCredentials');

    // ==================== MEDICINES ====================

    Route::resource(
        'medicines',
        MedicineController::class
    );

    // ==================== CATEGORIES ====================

    Route::resource(
        'categories',
        CategoryController::class
    );

    Route::patch('/categories/{category}/toggle-status', [
        CategoryController::class,
        'toggleStatus',
    ])->name('categories.toggleStatus');

    Route::post('/categories/{category}/medicines', [
        CategoryController::class,
        'attachMedicine',
    ])->name('categories.medicines.attach');

    Route::delete('/categories/{category}/medicines/{link}', [
        CategoryController::class,
        'detachMedicine',
    ])->name('categories.medicines.detach');

    Route::post('/categories/{category}/review/{link}', [
        CategoryController::class,
        'approveReview',
    ])->name('categories.review.approve');

    Route::post('/categories/sync', [
        CatalogImportController::class,
        'syncCategories',
    ])->name('categories.sync');

    Route::post('/categories/classify', [
        CatalogImportController::class,
        'classifySubcategories',
    ])->name('categories.classify');

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

    // ==================== TEMP: ADMIN MAINTENANCE TOKEN ====================
    // مؤقت — يصدر Sanctum token من جلسة ويب أدمن للوصول إلى admin API maintenance endpoints.
    Route::get('/admin/maintenance/token', [\App\Http\Controllers\Api\AdminTokenController::class, 'issue'])
        ->name('admin.maintenance.token');
});

// ==================== PHARMACY ONLY ====================

Route::middleware(['auth', 'role:pharmacy'])->group(function () {

    // ==================== PASSWORD CHANGE (forced on first login) ====================
    // 🔴 إلزامي: كلمة مرور الصيدلية المؤقتة (يولّدها الأدمن) لا يجوز أن تُستخدم
    // للوصول للوحة. الوسيط password.changed يعيد كل المسارات الأخرى إلى هنا.
    // ملاحظة الترتيب: هذا المسار مسجَّل **قبل** profile.complete لأنه إجراء أمني.

    Route::get('/pharmacy/password/change', [
        \App\Http\Controllers\web\Auth\PasswordChangeController::class,
        'show',
    ])->name('pharmacy.password.change.show');

    Route::post('/pharmacy/password/change', [
        \App\Http\Controllers\web\Auth\PasswordChangeController::class,
        'update',
    ])->name('pharmacy.password.change');

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

Route::middleware(['auth', 'role:pharmacy', 'password.changed', 'profile.complete'])->group(function () {

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

    // ==================== PHARMACY BULK INVENTORY IMPORT ====================
    // الاستيراد الجماعي: مسار منفصل تماماً عن نقاط الكتابة العادية.
    //
    // ⚠️ قاعدة الحد (أُصلحت بعد بلاغ 429):
    //    `throttle:inventory-import` يحرس **الأفعال** فقط — رفع/قرارات/تنفيذ/إلغاء.
    //    لا يحرس تحميل الصفحات (index/show) ولا التنزيلات (template/errors).
    //
    //    كان مطبَّقاً على المجموعة كلها، فكان مجرّد *فتح صفحة الاستيراد* يستهلك
    //    من حصة الساعة (10/ساعة) — أي 11 فتحة صفحة = HTTP 429 بلا رفع واحد.
    //    الصفحات محميّة بـ auth + role:pharmacy، والحمل الثقيل كله في POST.

    Route::get('/pharmacy/inventory/import', [
        PharmacyImportController::class,
        'index',
    ])->name('pharmacy.inventory.import.index');

    Route::get('/pharmacy/inventory/import/template', [
        PharmacyImportController::class,
        'template',
    ])->name('pharmacy.inventory.import.template');

    Route::post('/pharmacy/inventory/import', [
        PharmacyImportController::class,
        'preview',
    ])->middleware('throttle:inventory-import')->name('pharmacy.inventory.import.preview');

    // uuid وليس المفتاح الرقمي — لا تعداد للجلسات
    Route::get('/pharmacy/inventory/import/{import}', [
        PharmacyImportController::class,
        'show',
    ])->name('pharmacy.inventory.import.show');

    Route::post('/pharmacy/inventory/import/{import}/decide', [
        PharmacyImportController::class,
        'decide',
    ])->middleware('throttle:inventory-import')->name('pharmacy.inventory.import.decide');

    Route::post('/pharmacy/inventory/import/{import}/commit', [
        PharmacyImportController::class,
        'commit',
    ])->middleware('throttle:inventory-import')->name('pharmacy.inventory.import.commit');

    Route::get('/pharmacy/inventory/import/{import}/errors', [
        PharmacyImportController::class,
        'errors',
    ])->name('pharmacy.inventory.import.errors');

    Route::post('/pharmacy/inventory/import/{import}/cancel', [
        PharmacyImportController::class,
        'cancel',
    ])->middleware('throttle:inventory-import')->name('pharmacy.inventory.import.cancel');

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
    //
    // ملاحظة مهمة (١): الـ resource أدناه مُستثنى من 'create' عمدًا لأن الـ
    // route الصريح فوق يحمل نفس الاسم (pharmacy.alternatives.create). وجود
    // نسختين بنفس الاسم يمنع `php artisan route:cache` من العمل:
    //   "Unable to prepare route [pharmacy/alternatives/create] for
    //    serialization. Another route has already been assigned name"
    // وهذا كان يُفشل النشر على Render.
    Route::get('pharmacy/alternatives/create/{pharmacyMedicine?}', [
        PharmacyAlternativeController::class,
        'create',
    ])->name('pharmacy.alternatives.create');

    Route::resource(
        'pharmacy/alternatives',
        PharmacyAlternativeController::class
    )->only(['index', 'store'])
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

    // ==================== PHARMACY ACCOUNTING ====================
    // وحدة محاسبة الصيدلية — Frontend-only حاليًا (لا Backend محاسبة).
    // middleware: auth + role:pharmacy + profile.complete (نفس مسارات الصيدلية أعلاه).
    // المسارات المضافة هي المتاحة فعليًا؛ وبقية شجرة المحاسبة (المشتريات/المصروفات/
    // الموردون/العملاء/الصندوق/المدفوعات/الأرباح/التقارير) تُضاف عند بناء صفحاتها.

    Route::prefix('pharmacy/accounting')->name('pharmacy.accounting.')->group(function () {
        Route::get('/', [AccountingController::class, 'overview'])->name('overview');

        // المبيعات
        Route::get('/sales', [AccountingController::class, 'sales'])->name('sales.index');
        Route::get('/sales/create', [AccountingController::class, 'saleCreate'])->name('sales.create');
        // ملاحظة: يأتي بعد /sales/create حتى لا يبتلع {number} المسار الثابت
        Route::get('/sales/{number}', [AccountingController::class, 'saleShow'])
            ->where('number', '[A-Za-z0-9\-]+')
            ->name('sales.show');
    });
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
