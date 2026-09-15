<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * محاسبة الصيدلية — الجزء الثاني: الفواتير (البيع) وأسطرها.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * قرارات مثبّتة:
 * ══════════════════════════════════════════════════════════════════════════
 *
 * (١) **لا soft delete على الفواتير.** الفاتورة تُلغى بـ`status='cancelled`
 *     ولا تُحذف أبدًا. الحذف الصامت يمحو مسارًا تدقيقيًا (audit trail) وهو
 *     مرفوض محاسبيًا. `down()` هنا يحذف الجدول، وهذا مقبول كهجرة فقط.
 *
 * (٢) **`number` فريد لكل صيدلية، لا عالميًا.** كل صيدلية لها تسلسل فواتيرها
 *     الخاص (INV-1001، INV-1002 ...). التفرد المركّب `(pharmacy_id, number)`
 *     هو الصحيح، وإلا تجاوزت صيدليتان في رقم واحد بمجرد أن تتشابه التسلسلات.
 *
 * (٣) **`items_count` مخزّن صريحًا.** شاشة المبيعات تعرض عدد الأصناف لكل فاتورة
 *     في كل صف — استعلامه بـ`withCount` لكل صف هو N+1 خفي. عمود مخزّن يُحدَّث
 *     في نفس transaction الحفظ يحوّل ذلك إلى قراءة صف واحد.
 *
 * (٤) **`total` محسوب ومخزّن، ولا يُعاد حسابه في الاستعلام.** مجموع سطر =
 *     `quantity × unit_price − line_discount`. المجموع الكلي =
 *     `subtotal − discount + (بلا ضريبة هنا)`. تخزينه يجعل ترقيم الصفحات
 *     وفرز العمود في SQL مباشرًا بدل جمع في PHP لكل صفحة.
 *
 * (٥) **`medicine_id` قابل للـNULL مع `nullOnDelete`.** لو حُذف الدواء من
 *     الكتالوج، يجب أن تبقى الفاتورة التاريخية كاملة. لذلك **نُنسخ اسم الدواء
 *     وسعره في السطر** (`medicine_name`, `unit_price`) — السنابشوت هو المصدر
 *     الحقيقي للسجل التاريخي، و`medicine_id` مجرد إشارة اختيارية.
 *     هذا أهم قرار في الجداول: الفاتورة لا يجوز أن تتغيّر قيمتها لأن الكتالوج تغيّر.
 *
 * (٦) `pharmacy_medicine_id` أيضًا nullable بنفس المنطق — يُستخدم لخصم المخزون
 *     ولا يُعتمد عليه للقراءة التاريخية.
 */

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pharmacy_id')->constrained('pharmacies')->cascadeOnDelete();
            // رقم الفاتورة المعروض — فريد داخل الصيدلية فقط
            $table->string('number', 30);
            // العميل: nullable لأن أغلب مبيعات الصيدلية نقدية بلا اسم.
            // nullOnDelete: حذف العميل نهائيًا لا يحذف فواتيره (تتحول لنقدية).
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            // سنابشوت اسم العميل — لو حُذف العميل نبقى نعرف لمن بِيعت
            $table->string('customer_name', 150)->nullable();

            $table->decimal('subtotal', 12, 2)->default(0.00);   // مجموع الأسطر قبل الخصم
            $table->decimal('discount', 12, 2)->default(0.00);   // خصم على مستوى الفاتورة
            $table->decimal('total', 12, 2)->default(0.00);      // subtotal - discount
            $table->decimal('paid', 12, 2)->default(0.00);       // المدفوع
            $table->decimal('remaining', 12, 2)->default(0.00);  // total - paid

            // cash | card | bank_transfer | credit
            $table->string('payment_method', 20)->default('cash');
            // paid | partially_paid | unpaid | refunded | cancelled
            $table->string('status', 20)->default('paid');

            $table->unsignedSmallInteger('items_count')->default(0);
            $table->text('notes')->nullable();

            // المستخدم الذي أنشأ الفاتورة — للتدقيق. nullable لأن حذف الحساب
            // لا يجب أن يمنع بقاء السجل المالي.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // تاريخ البيع الفعلي (قد يختلف عن created_at في تسجيل متأخر)
            $table->timestamp('sold_at')->index();
            $table->timestamps();

            // رقم الفاتورة فريد داخل الصيدلية — لا عالميًا
            $table->unique(['pharmacy_id', 'number'], 'uniq_sale_number');
            // فهرس الصفحة الرئيسية: قائمة مبيعات صيدلية مرتّبة زمنيًا
            $table->index(['pharmacy_id', 'sold_at'], 'sales_pharmacy_sold_at_index');
            // فهرس الفلترة بالحالة (شاشة المبيعات بها فلتر حالة)
            $table->index(['pharmacy_id', 'status'], 'sales_pharmacy_status_index');
            // فهرس فلترة طريقة الدفع
            $table->index(['pharmacy_id', 'payment_method'], 'sales_pharmacy_method_index');
            // فهرس كشف حساب العميل
            $table->index(['customer_id', 'sold_at'], 'sales_customer_sold_at_index');
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();

            // إشارات اختيارية — لا يُعتمد عليها للقراءة التاريخية (انظر ملاحظة ٥)
            $table->foreignId('medicine_id')->nullable()->constrained('medicines')->nullOnDelete();
            $table->foreignId('pharmacy_medicine_id')->nullable()->constrained('pharmacy_medicines')->nullOnDelete();

            // ── السنابشوت: المصدر الحقيقي للسجل التاريخي ──
            $table->string('medicine_name', 200);
            $table->string('barcode', 20)->nullable();
            $table->decimal('unit_price', 12, 2)->default(0.00);
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('line_discount', 12, 2)->default(0.00);
            // quantity × unit_price − line_discount — مخزون لتفادي إعادة الحساب
            $table->decimal('line_total', 12, 2)->default(0.00);

            $table->timestamps();

            // لا فهرس صريح على sale_id: مفتاح الربط الأجنبي ينشئ فهرسه تلقائيًا.
            // إضافة فهرس ثانٍ بنفس العمود مساحة مهدورة بلا فائدة استعلامية.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};
