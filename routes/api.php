<?php

use App\Http\Controllers\Api\AccountingCashController;
use App\Http\Controllers\Api\AccountingExpenseController;
use App\Http\Controllers\Api\AccountingOverviewController;
use App\Http\Controllers\Api\AccountingPartiesController;
use App\Http\Controllers\Api\AccountingRefundController;
use App\Http\Controllers\Api\AccountingSalesController;
use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Api\Admin\LogSettingController as AdminLogSettingController;
use App\Http\Controllers\Api\Admin\MedicineController as AdminMedicineController;
use App\Http\Controllers\Api\Admin\MedicineRequestController as AdminMedicineRequestController;
use App\Http\Controllers\Api\Admin\PatientInventoryController as AdminPatientInventoryController;
use App\Http\Controllers\Api\Admin\PharmacyController as AdminPharmacyController;
use App\Http\Controllers\Api\Admin\ProfileController as AdminProfileController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\AdminAuthController;
use App\Http\Controllers\Api\AdminPharmacyMohDryRunController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AvailabilityAlertController;
use App\Http\Controllers\Api\BarcodeLookupController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CouponController;
use App\Http\Controllers\Api\DeviceTokenController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\MedicalProfileController;
use App\Http\Controllers\Api\MedicineController;
use App\Http\Controllers\Api\MedicineRequestController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OcrController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PatientAssistantController;
use App\Http\Controllers\Api\PatientInquiryController;
use App\Http\Controllers\Api\PatientProfileController;
use App\Http\Controllers\Api\PharmacyAlternativeController;
use App\Http\Controllers\Api\PharmacyController;
use App\Http\Controllers\Api\PharmacyDashboardController;
use App\Http\Controllers\Api\PharmacyInquiryController;
use App\Http\Controllers\Api\PharmacyInventoryController;
use App\Http\Controllers\Api\PharmacyInventoryImportController;
use App\Http\Controllers\Api\PharmacyMedicineController;
use App\Http\Controllers\Api\PharmacyProfileController;
use App\Http\Controllers\Api\PharmacyPasswordResetRequestController;
use App\Http\Controllers\Api\PharmacyRatingController;
use App\Http\Controllers\Api\RatingController;
use App\Http\Controllers\Api\ReminderController;
use App\Http\Controllers\Api\SyncController;
use Illuminate\Support\Facades\Route;

Route::post('/otp/send', [AuthController::class, 'sendOtp'])->middleware('throttle:otp');
Route::post('/otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:otp-verify');
Route::post('/login/pharmacy', [AuthController::class, 'pharmacyLogin'])->middleware(['throttle:login', 'throttle:login-account']);

// لوحة الأدمن (React): دخول بالبريد الإلكتروني + كلمة المرور، ثم توكن Sanctum.
// نفس حدود الويب (throttle:login لكل IP + throttle:login-account لكل بريد)،
// لأن هذا مسار مصادقة حساس تماماً كدخول الصيدلية.
Route::post('/login/admin', [AdminAuthController::class, 'login'])->middleware(['throttle:login', 'throttle:login-account']);

Route::post('/register/pharmacy', [AuthController::class, 'pharmacyRegister'])->middleware('throttle:register');

// طلب استعادة كلمة مرور صيدلية — عام، بلا مصادقة. لا يُنشئ كلمة مرور ولا يكشف
// وجود الحساب: الردّ واحد سواء تطابقت البيانات أم لا. مراجعة الطلب من الأدمن
// فقط (مسارات admin أدناه). حد الـthrottle نفس حد التسجيل لأنه مسار حساس مماثل.
Route::post('/pharmacy/password-reset-requests', [PharmacyPasswordResetRequestController::class, 'store'])->middleware('throttle:register');

Route::post('/register/patient', [AuthController::class, 'patientRegister'])->middleware('throttle:register');

Route::get('/medicines', [MedicineController::class, 'index']);
Route::get('/medicines/search', [MedicineController::class, 'search']);
Route::get('/medicines/active-ingredient/{ingredient}', [MedicineController::class, 'byActiveIngredient']);
Route::get('/medicines/{id}', [MedicineController::class, 'show']);
Route::get('/medicines/{id}/pharmacies', [MedicineController::class, 'pharmacies']);

// ط­ظ„ظ‘ ط§ط³ظ… ط§ظ„ط¯ظˆط§ط، ظ…ط¨ط§ط´ط±ط© ط¹ط¨ط± MedicineResolver (ط¨ط¯ظˆظ† ط§ظ†طھط¸ط§ط± ط®ط¯ظ…ط© AI)
// ظ…ظپظٹط¯ ظ„ظ„ط¨ط­ط« ط§ظ„ظپظˆط±ظٹ ظˆط§ظ„طھط·ط¨ظٹظ‚ط§طھ ط§ظ„طھظٹ طھط±ظٹط¯ ظ†طھط§ط¦ط¬ ظپظˆط±ظٹط© ط¨ط§ظ„ط¹ط±ط¨ظٹط©/ط§ظ„ط¥ظ†ط¬ظ„ظٹط²ظٹط©
Route::post('/medicines/resolve', [MedicineController::class, 'resolve'])->middleware('auth:sanctum')->middleware('throttle:30,1');

// البحث بالباركود (read-only من DB المحلي — لا يضرب مزوّداً خارجياً، بلا auth)
Route::get('/medicines/barcode/{barcode}', [BarcodeLookupController::class, 'show'])->middleware('throttle:60,1');

// صيدليات متوفر بها دواء كتالوج الوزارة — مرتبة من الأقرب حسب موقع المستخدم
// (نقطة عند الضغط على دواء في الأقسام/الفلاتر)، read-only، بلا auth
Route::get('/moh-medicines/{moh}/pharmacies', [MedicineController::class, 'mohPharmacies'])->middleware('throttle:60,1');

// Pharmacies Routes Public
Route::get('/pharmacies', [PharmacyController::class, 'index']);
Route::get('/pharmacies/{id}', [PharmacyController::class, 'show']);

// OCR (ظ…ط­ظ…ظٹط© â€” ظ„ظ„ظ…ط³طھط®ط¯ظ…ظٹظ† ط§ظ„ظ…ط³ط¬ظ„ظٹظ†)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/ocr/medicine', [OcrController::class, 'identify'])->middleware('throttle:30,1');
});

// âœ… Routes Protected
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    // H4: rate-limit ط¹ظ„ظ‰ طھط¬ط¯ظٹط¯ ط§ظ„ظ€ token (30 ط·ظ„ط¨/ط¯ظ‚ظٹظ‚ط©) ظ„ظ…ظ†ط¹ ط¥ط·ط§ظ„ط© ط¹ظ…ط± token ظ…ط³ط±ظˆظ‚.
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

    // Patient inquiries â€” M-9: throttle ظ…ط®طµطµ ظ„ظ„ظƒطھط§ط¨ط© ط¹ظ„ظ‰ store (ظٹظˆظ„ظ‘ط¯ ط¥ط´ط¹ط§ط±ط§ظ‹ + FCM)
    // ط¨ط¯ظˆظ† ط£ط³ظ…ط§ط، طµط±ظٹط­ط© â€” ط§ظ„ظ…ط³ط§ط± ط§ظ„ظˆظٹط¨ ظٹط­ظ…ظ„ ط§ظ„ط§ط³ظ… ظ†ظپط³ظ‡ (route('patient.inquiries.store'))
    Route::get('patient/inquiries', [PatientInquiryController::class, 'index']);
    Route::post('patient/inquiries', [PatientInquiryController::class, 'store'])->middleware('throttle:writes');
    Route::get('patient/inquiries/{inquiry}/messages', [PatientInquiryController::class, 'messages']);
    Route::post('patient/inquiries/{inquiry}/messages', [PatientInquiryController::class, 'sendMessage'])->middleware('throttle:writes');

    // Patient-scoped routes (Phase 9 â€” SRS endpoints).
    // ظ…ظ„ط§ط­ط¸ط© ط¹ظ„ظ‰ طھط±طھظٹط¨ ط§ظ„ظ…ط³ط§ط±ط§طھ: `medicines/search` ظٹط¬ط¨ ط£ظ† ظٹط³ط¨ظ‚ `medicines/{medicine}`
    // ظˆط¥ظ„ط§ ظپط³ظٹظ„طھظ‚ط· Laravel ط§ظ„ظ€ wildcard ط£ظˆظ„ط§ظ‹ ظˆظٹظپط´ظ„ ظپظٹ ظ…ط·ط§ط¨ظ‚ط© "search" ظƒظ‚ظٹظ…ط©.
    Route::prefix('patient')->group(function () {
        Route::get('medicines/search', [MedicineController::class, 'search']);
        Route::get('medicines/{medicine}', [MedicineController::class, 'show']);
        // ظ…ظ„ط§ط­ط¸ط©: `pharmacies` ط¹ظ„ظ‰ MedicineController ظٹط¹ظٹط¯ ظ‚ط§ط¦ظ…ط© ط§ظ„طµظٹط¯ظ„ظٹط§طھ ط§ظ„طھظٹ ظٹطھظˆظپط± ط¨ظ‡ط§ ط§ظ„ط¯ظˆط§ط، â€”
        // ظˆظ‡ط°ط§ ظ†ظپط³ ط¯ظ„ط§ظ„ظٹط§ظ‹ ظ…ط¹ظ†ظ‰ "availability" ظپظٹ SRS ظ„ظ„ظ…ط±ظٹط¶.
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

        Route::get('addresses', [AddressController::class, 'index']);
        Route::post('addresses', [AddressController::class, 'store']);
        Route::get('addresses/{address}', [AddressController::class, 'show']);
        Route::put('addresses/{address}', [AddressController::class, 'update']);
        Route::delete('addresses/{address}', [AddressController::class, 'destroy']);

        Route::get('cart', [CartController::class, 'show'])->name('cart.show');
        Route::post('cart/items', [CartController::class, 'store'])->name('cart.store');
        Route::put('cart/items/{item}', [CartController::class, 'update'])->name('cart.update');
        Route::delete('cart/items/{item}', [CartController::class, 'destroy'])->name('cart.destroy');
        Route::delete('cart', [CartController::class, 'clear'])->name('cart.clear');

        Route::post('checkout', [OrderController::class, 'checkout'])->name('checkout');
        Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
        Route::get('orders/{order}/tracking', [OrderController::class, 'tracking'])->name('orders.tracking');
        Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');

        Route::post('coupons/validate', [CouponController::class, 'validateCoupon']);

        // مساعد المريض النصّي: رسالة حرة → دواء → صيدليات متوفرة فعلاً مرتّبة.
        // حدّ مخصّص (assistant) لأن كل طلب قد يستدعي خدمة AI خارجية، ولأن
        // السقف العام 'api' (60/دقيقة) فضفاض لمسار بهذه التكلفة.
        Route::post('assistant/chat', [PatientAssistantController::class, 'chat'])
            ->middleware('throttle:assistant');
    });

    // Device tokens (FCM) â€” ط®ط§ط±ط¬ prefix('patient') ظ„ط£ظ† ظƒظ„ط§ ط§ظ„ظ€ roles (patient/pharmacy) ظ‚ط¯ ظٹط³ط¬ظ‘ظ„ط§ظ† ط¬ظ‡ط§ط²ط§ظ‹.
    Route::post('device-tokens', [DeviceTokenController::class, 'store'])->middleware('throttle:writes');
    Route::delete('device-tokens/current', [DeviceTokenController::class, 'destroy']);

    // Pharmacy inquiries (role-checked)
    Route::middleware('role:pharmacy')->prefix('pharmacy')->group(function () {
        Route::get('inquiries', [PharmacyInquiryController::class, 'index']);
        Route::get('inquiries/{inquiry}', [PharmacyInquiryController::class, 'show']);
        Route::put('inquiries/{inquiry}', [PharmacyInquiryController::class, 'update']);
        Route::get('inquiries/{inquiry}/messages', [PharmacyInquiryController::class, 'messages']);
        Route::post('inquiries/{inquiry}/messages', [PharmacyInquiryController::class, 'sendMessage'])->middleware('throttle:writes');
        Route::get('ratings', [PharmacyRatingController::class, 'index']);

        Route::get('medicines/search', [PharmacyMedicineController::class, 'search']);
        Route::post('medicine-requests', [MedicineRequestController::class, 'store'])->middleware('throttle:writes');
        Route::get('medicine-requests', [MedicineRequestController::class, 'index']);
        // ط¥ط¶ط§ظپط© ط¯ظˆط§ط، ط¨ط§ظ„ط§ط³ظ… ظ…ط¨ط§ط´ط±ط© (ظ„ظ„ظ…ظˆط¨ط§ظٹظ„) â€” ط¨ط¯ظˆظ† medicine_id ط£ظˆ moh_medicine_id
        Route::post('medicines/by-name', [PharmacyMedicineController::class, 'storeByName'])->middleware('throttle:writes');
        Route::apiResource('medicines', PharmacyMedicineController::class)
            ->names('api.pharmacy.medicines');
        Route::get('medicines/{medicine}/alternatives', [PharmacyMedicineController::class, 'alternatives']);

        Route::get('inventory', [PharmacyInventoryController::class, 'index']);
        Route::put('inventory/{medicine}', [PharmacyInventoryController::class, 'update'])->middleware('throttle:writes');
        Route::post('inventory/bulk', [PharmacyInventoryController::class, 'bulkUpdate'])->middleware('throttle:writes');

        // ==================== BULK INVENTORY IMPORT ====================
        // ظ…ط³ط§ط± ظ…ظ†ظپطµظ„ ط¨ط­ط¯ ظ…ط¹ط¯ظ„ ظ…ط®طµظ‘طµ (user_id + pharmacy_id) â€” ظ„ط§ ظٹظ…ط³ظ‘ 'writes'.
        // ظ…ظڈط³ط¬ظژظ‘ظ„ ظ‚ط¨ظ„ inventory/{medicine} ط§ظ„ط¶ظ…ظ†ظٹ ط­طھظ‰ ظ„ط§ ظٹظڈظ„طھظ‚ط· 'import' ظƒظ…ط¹ط±ظ‘ظپ.
        //
        // âڑ ï¸ڈ ط§ظ„ط­ط¯ظ‘ ط¹ظ„ظ‰ **ط§ظ„ط£ظپط¹ط§ظ„** ظپظ‚ط· (preview/decide/commit/cancel) â€” ط§ظ„ظ‚ط±ط§ط،ط©
        // (template/show/errors) ظ„ط§ طھط³طھظ‡ظ„ظƒ ط­طµط© ط§ظ„ط§ط³طھظٹط±ط§ط¯. ط±ط§ط¬ط¹ routes/web.php.

        Route::get('inventory/import/template', [PharmacyInventoryImportController::class, 'template']);
        Route::get('inventory/import/{import}', [PharmacyInventoryImportController::class, 'show']);
        Route::get('inventory/import/{import}/errors', [PharmacyInventoryImportController::class, 'errors']);

        Route::middleware('throttle:inventory-import')->group(function () {
            Route::post('inventory/import', [PharmacyInventoryImportController::class, 'preview']);
            Route::post('inventory/import/{import}/decide', [PharmacyInventoryImportController::class, 'decide']);
            Route::post('inventory/import/{import}/commit', [PharmacyInventoryImportController::class, 'commit']);
            Route::post('inventory/import/{import}/cancel', [PharmacyInventoryImportController::class, 'cancel']);
        });

        Route::get('alternatives', [PharmacyAlternativeController::class, 'index']);
        Route::post('alternatives', [PharmacyAlternativeController::class, 'store']);
        Route::delete('alternatives/{base}/{alternative}', [PharmacyAlternativeController::class, 'destroy']);

        Route::get('dashboard/stats', [PharmacyDashboardController::class, 'stats']);
        Route::post('change-password', [PharmacyProfileController::class, 'changePassword']);

        Route::post('orders/{order}/status', [OrderController::class, 'updateStatus'])->name('api.pharmacy.orders.status');

        // ==================== ACCOUNTING ====================
        // محاسبة الصيدلية: فواتير بيع · مصروفات · عملاء/موردون · صندوق.
        //
        // ⚠️ الترتيب مهم: `sales-summary` قبل `sales/{number}` — وإلا التقط
        // المسار الديناميكي كلمة `summary` كرقم فاتورة. نفس قاعدة
        // `inventory/import/template` أعلاه.
        //
        // ⚠️ `sales/{number}` يستقبل **رقم الفاتورة** لا الـid (INV-1042)،
        // والبحث مقيّد بـ pharmacy_id داخل الاستعلام (لا IDOR).
        //
        // ⚠️ الكتّاب (store/cancel/payments) تحت `throttle:writes` — نفس
        // سياسة بقية مسارات الكتابة في المشروع. القراء بلا حدّ مخصّص
        // (يخضعون لـ`throttle:api` العام).
        Route::prefix('accounting')->group(function () {
            Route::get('overview', [AccountingOverviewController::class, 'show'])
                ->name('api.pharmacy.accounting.overview');

            // القراءة أولًا كي لا تلتقط المسارات الديناميكية الكلمات الثابتة.
            Route::get('sales-summary', [AccountingSalesController::class, 'summary'])
                ->name('api.pharmacy.accounting.sales.summary');
            Route::get('sales', [AccountingSalesController::class, 'index'])
                ->name('api.pharmacy.accounting.sales.index');
            Route::get('sales/{number}', [AccountingSalesController::class, 'show'])
                ->name('api.pharmacy.accounting.sales.show');

            // الإرجاعات — قراءة. مسارات `sales/{number}/refunds*` تُعلَن
            // هنا (لا بعد `sales/{number}` أعلاه) مع أنّ Laravel يحلّ
            // الأطول أولًا — لكن الإعلان الصريح يجعل النية واضحة ويحمي من
            // أي إعادة ترتيب لاحقة.
            Route::get('refunds', [AccountingRefundController::class, 'index'])
                ->name('api.pharmacy.accounting.refunds.index');
            Route::get('refunds/{refund}', [AccountingRefundController::class, 'show'])
                ->where('refund', '[0-9]+')
                ->name('api.pharmacy.accounting.refunds.show');
            Route::get('sales/{number}/refunds', [AccountingRefundController::class, 'indexForSale'])
                ->name('api.pharmacy.accounting.sales.refunds');
            Route::get('sales/{number}/refund-items', [AccountingRefundController::class, 'availableItems'])
                ->name('api.pharmacy.accounting.sales.refund-items');

            Route::get('expense-categories', [AccountingExpenseController::class, 'categories'])
                ->name('api.pharmacy.accounting.expense-categories');
            Route::get('expenses', [AccountingExpenseController::class, 'index'])
                ->name('api.pharmacy.accounting.expenses.index');
            Route::post('expenses/{expense}/cancel', [AccountingExpenseController::class, 'cancel'])
                ->name('api.pharmacy.accounting.expenses.cancel')
                ->middleware('throttle:writes');

            Route::get('customers', [AccountingPartiesController::class, 'customers'])
                ->name('api.pharmacy.accounting.customers.index');
            Route::get('customers/{customer}', [AccountingPartiesController::class, 'showCustomer'])
                ->name('api.pharmacy.accounting.customers.show');
            Route::get('suppliers', [AccountingPartiesController::class, 'suppliers'])
                ->name('api.pharmacy.accounting.suppliers.index');

            Route::get('cash', [AccountingCashController::class, 'show'])
                ->name('api.pharmacy.accounting.cash.index');

            // الكتابة مجمّعة تحت حدّ `writes`.
            Route::middleware('throttle:writes')->group(function () {
                Route::post('sales', [AccountingSalesController::class, 'store'])
                    ->name('api.pharmacy.accounting.sales.store');
                Route::post('sales/{number}/cancel', [AccountingSalesController::class, 'cancel'])
                    ->name('api.pharmacy.accounting.sales.cancel');

                Route::post('expenses', [AccountingExpenseController::class, 'store'])
                    ->name('api.pharmacy.accounting.expenses.store');

                Route::post('customers', [AccountingPartiesController::class, 'storeCustomer'])
                    ->name('api.pharmacy.accounting.customers.store');
                Route::post('customers/{customer}/payments', [AccountingPartiesController::class, 'storeCustomerPayment'])
                    ->name('api.pharmacy.accounting.customers.payments');

                Route::post('suppliers', [AccountingPartiesController::class, 'storeSupplier'])
                    ->name('api.pharmacy.accounting.suppliers.store');
                Route::post('suppliers/{supplier}/payments', [AccountingPartiesController::class, 'storeSupplierPayment'])
                    ->name('api.pharmacy.accounting.suppliers.payments');

                Route::post('cash/adjustments', [AccountingCashController::class, 'storeAdjustment'])
                    ->name('api.pharmacy.accounting.cash.adjustments');

                // الإرجاعات — كتابة (تُنشئ قيدًا في الدفتر وتُعيد المخزون).
                Route::post('refunds', [AccountingRefundController::class, 'store'])
                    ->name('api.pharmacy.accounting.refunds.store');
            });
        });
    });

    // Ratings
    Route::get('ratings', [RatingController::class, 'index']);
    Route::post('ratings', [RatingController::class, 'store'])->middleware('throttle:writes');
    Route::get('ratings/{rating}', [RatingController::class, 'show'])->name('api.ratings.show');
    Route::put('ratings/{rating}', [RatingController::class, 'update'])->middleware('throttle:writes')->name('api.ratings.update');
    Route::delete('ratings/{rating}', [RatingController::class, 'destroy'])->name('api.ratings.destroy');
});

// Offline-first Sync (Pharmacy web PWA)
Route::middleware('auth')->post('/sync/token', [SyncController::class, 'issueToken']);

Route::middleware(['auth:sanctum', 'role:pharmacy'])->prefix('sync')->group(function () {
    Route::post('push', [SyncController::class, 'push'])->middleware('throttle:writes');
    Route::get('pull', [SyncController::class, 'pull']);
});

// ===== Admin-only: Dry-run للتحقق من ربط pharmacy_medicines ↔ moh_medicines =====
// ⚠️ Read-Only: لا تنفذ أي تعديل بيانات. مؤقتة — تغريد بعد الاستخدام.
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin/maintenance')->group(function () {
    Route::get('pharmacy-moh-backfill/dry-run', AdminPharmacyMohDryRunController::class);
});

// Categories (public catalog metadata)
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/categories/{category}', [CategoryController::class, 'show']);
Route::get('/categories/{category}/medicines', [CategoryController::class, 'medicines']);

// Admin: full catalog (no availability filter)
Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
    Route::get('/admin/categories/{category}/medicines', [CategoryController::class, 'adminMedicines']);
});

// ط£ط´ظƒط§ظ„ ط§ظ„ط¬ط±ط¹ط§طھ (ظ‚ط§ط¦ظ…ط© ط£ط¹ط±ط§ظپ canonical ظ„ط§ط³طھط®ط¯ط§ظ…ظ‡ط§ ظ…ط¹ ظپظ„طھط± dosage_form)
Route::get('/dosage-forms', [CategoryController::class, 'dosageForms']);

// كل خيارات فلاتر الكتالوج في نداء واحد (أقسام + أقسام فرعية + أشكال دوائية +
// فئات عمرية) مع عدد الأدوية لكل خيار — تبني منه الواجهة شاشة "تصفية النتائج".
Route::get('/medicine-filters', [CategoryController::class, 'filters']);

// =============================================================================
// لوحة الأدمن (React) — Admin API
// =============================================================================
// لماذا مجموعة مستقلة بدل توسيع المسارات الموجودة؟
//   · المسارات الحالية للصيدلية/المريض وواجهتها عقد مُثبَّت — لا نلمسه.
//   · كل مسار هنا `auth:sanctum` + `role:admin` صراحةً. لا استثناء.
//   · المسارات الويب (Blade) تبقى تعمل بالتوازي — هذه إضافة لا استبدال،
//     فيمكن النشر والتراجع بلا كسر لوحة الأدمن الحالية.
//
// ملاحظة تسمية: `/api/admin/categories/{category}/medicines` مأخوذ أعلاه
// (كتالوج كامل بلا فلتر توفّر) — لذلك لا أُعيد تعريفه هنا، ومسار الروابط
// اسمه `/links` صراحةً لتفادي الغموض.
// =============================================================================
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function () {
    // Dashboard
    Route::get('/dashboard', [AdminDashboardController::class, 'index']);

    // طلبات استعادة كلمة مرور الصيدليات — المراجعة فقط (موافقة تولّد كلمة
    // مرور جديدة وتُلغي الجلسات القائمة، رفض يسجّل سبب الأدمن).
    Route::get('/pharmacy-password-reset-requests', [PharmacyPasswordResetRequestController::class, 'index']);
    Route::post('/pharmacy-password-reset-requests/{passwordResetRequest}/approve', [PharmacyPasswordResetRequestController::class, 'approve']);
    Route::post('/pharmacy-password-reset-requests/{passwordResetRequest}/reject', [PharmacyPasswordResetRequestController::class, 'reject']);

    // Pharmacies
    Route::get('/pharmacies', [AdminPharmacyController::class, 'index']);
    Route::post('/pharmacies', [AdminPharmacyController::class, 'store']);
    Route::get('/pharmacies/{pharmacy}', [AdminPharmacyController::class, 'show']);
    Route::match(['put', 'patch'], '/pharmacies/{pharmacy}', [AdminPharmacyController::class, 'update']);
    Route::delete('/pharmacies/{pharmacy}', [AdminPharmacyController::class, 'destroy']);
    Route::patch('/pharmacies/{pharmacy}/toggle-status', [AdminPharmacyController::class, 'toggleStatus']);
    Route::patch('/pharmacies/{pharmacy}/reset-credentials', [AdminPharmacyController::class, 'resetCredentials']);

    // Medicines (catalog)
    Route::get('/medicines', [AdminMedicineController::class, 'index']);
    Route::post('/medicines', [AdminMedicineController::class, 'store']);
    Route::get('/medicines/{medicine}', [AdminMedicineController::class, 'show']);
    Route::match(['put', 'patch'], '/medicines/{medicine}', [AdminMedicineController::class, 'update']);
    Route::delete('/medicines/{medicine}', [AdminMedicineController::class, 'destroy']);

    // Medicine requests
    Route::get('/medicine-requests', [AdminMedicineRequestController::class, 'index']);
    Route::get('/medicine-requests/{medicineRequest}', [AdminMedicineRequestController::class, 'show']);
    Route::post('/medicine-requests/{medicineRequest}/approve', [AdminMedicineRequestController::class, 'approve']);
    Route::post('/medicine-requests/{medicineRequest}/reject', [AdminMedicineRequestController::class, 'reject']);

    // Users
    Route::get('/users', [AdminUserController::class, 'index']);
    Route::post('/users', [AdminUserController::class, 'store']);
    Route::get('/users/{user}', [AdminUserController::class, 'show']);
    Route::match(['put', 'patch'], '/users/{user}', [AdminUserController::class, 'update']);
    Route::delete('/users/{user}', [AdminUserController::class, 'destroy']);
    Route::patch('/users/{user}/toggle-status', [AdminUserController::class, 'toggleStatus']);

    // Patients + Inventory (read-only)
    Route::get('/patients', [AdminPatientInventoryController::class, 'patients']);
    Route::get('/inventory', [AdminPatientInventoryController::class, 'inventory']);

    // Categories
    Route::get('/categories', [AdminCategoryController::class, 'index']);
    Route::patch('/categories/{category}/toggle-status', [AdminCategoryController::class, 'toggleStatus']);
    Route::get('/categories/{category}/links', [AdminCategoryController::class, 'links']);
    Route::post('/categories/{category}/medicines', [AdminCategoryController::class, 'attachMedicine']);
    Route::delete('/categories/{category}/medicines/{link}', [AdminCategoryController::class, 'detachMedicine']);
    Route::post('/categories/{category}/review/{link}', [AdminCategoryController::class, 'approveReview']);

    // Activity log + settings
    Route::get('/logs', [AdminLogSettingController::class, 'logs']);
    Route::get('/settings', [AdminLogSettingController::class, 'settings']);
    Route::post('/settings', [AdminLogSettingController::class, 'updateSettings']);

    // Own profile — display + password change.
    // NOTE: named `change-password` to mirror the pharmacy route's shape, but a
    // SEPARATE endpoint on purpose: the pharmacy one aborts unless
    // `role === 'pharmacy'`, so it can never serve an admin.
    Route::get('/profile', [AdminProfileController::class, 'show']);
    Route::post('/change-password', [AdminProfileController::class, 'changePassword'])
        ->middleware('throttle:6,1');
});

