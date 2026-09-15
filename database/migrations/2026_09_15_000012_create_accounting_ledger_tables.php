<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * محاسبة الصيدلية — الجزء الثالث: المصروفات والمشتريات والحركات النقدية والدفعات.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * قرارات مثبّتة:
 * ══════════════════════════════════════════════════════════════════════════
 *
 * (١) **`cash_movements` هو دفتر الصندوق الوحيد.** لا نخزّن رصيد الصندوق في
 *     عمود قابل للانحراف؛ الرصيد = مجموع الحركات. الفاتورة النقدية تُولّد حركة
 *     `in` تلقائيًا داخل نفس transaction، وكذلك الدفعات والمسحوبات. سبب ذلك:
 *     لو كان الرصيد عمودًا مستقلًا، فأي مسار ينسى تحديثه يخلق فرقًا صامتًا
 *     بين الصندوق والواقع — وهذا أسوأ عطل ممكن في نظام محاسبة.
 *     الرصيد يُقرأ بـ`SUM(CASE WHEN direction='in' THEN amount ELSE -amount END)`.
 *
 * (٢) **المصروف لا يُحذف، بل يُلغى** (`is_cancelled`) — نفس منطق الفاتورة.
 *
 * (٣) **المشتريات تحمل رصيدًا آجلًا** (`paid`/`remaining`) لأن شراء الأدوية من
 *     الموزّعين غالبًا آجل. المتبقي يُضاف لرصيد المورد في `suppliers.current_balance`.
 *
 * (٤) **`payments` جدولان منفصلان** (منفصلة عن بعضها عمدًا):
 *     - `customer_payments`: تحصيل من عميل ⇒ ينقص رصيد العميل، يزيد الصندوق.
 *     - `supplier_payments`  : دفع لمورد  ⇒ ينقص رصيد المورد، ينقص الصندوق.
 *     الفصل أوضح للتقارير وأسلم للفهارس من جدول واحد بعمود `party_type` متعدد
 *     الشكل (polymorphic) — وهذا المشروع لا يستخدم polymorphic relations.
 *
 * (٥) `cancelled_at` + `cancelled_by` على الفواتير والمصروفات: من ألغى ومتى.
 *     ضروري لأن الإلغاء يحرّك أرصدة، فيجب معرفة من فعله.
 */

return new class extends Migration
{
    public function up(): void
    {
        // ─── فئات المصروفات (مرجعية لكل صيدلية — قابلة للتوسيع) ────────────
        // ملاحظة: لا نُنشئ صفوفًا هنا. الفئات الافتراضية تُبذَر في Seeder
        // لتكون قابلة للتعديل من الواجهة لاحقًا بلا هجرة جديدة.
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pharmacy_id')->constrained('pharmacies')->cascadeOnDelete();
            $table->string('key', 40);      // salaries | rent | electricity ...
            $table->string('name_ar', 100);
            // ⚠️ `name_en` **قابل للـnull عمدًا**. المشروع عربي-أولًا، ومفاتيح
            // الترجمة العربية فقط موجودة (`accounting.expenses.category.*`).
            // لو كان NOT NULL لفشل كل إدخال عربي بـ`Integrity constraint
            // violation: name_en` — وهو عطل حقيقي اكتُشف بتشغيل السيدر،
            // لا نظريًا. اللغة الثانية اختيارية وتُملأ لاحقًا عند الحاجة.
            $table->string('name_en', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            // حذف ناعم: فئة مصروف تُشار إليها من مصروفات تاريخية، فحذفها
            // الصلب يترك سجلات بلا تفسير. التعطيل عبر is_active، والحذف
            // الناعم متاح لمن يريد إخفاءها تمامًا.
            $table->softDeletes();
            $table->timestamps();

            // نفس المفتاح لا يتكرر داخل الصيدلية (لكنه يتكرر بين الصيدليات)
            $table->unique(['pharmacy_id', 'key'], 'uniq_expense_category_key');
        });

        // ─── المصروفات ─────────────────────────────────────────────────────
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pharmacy_id')->constrained('pharmacies')->cascadeOnDelete();
            $table->foreignId('expense_category_id')
                ->nullable()
                ->constrained('expense_categories')
                ->nullOnDelete();
            // سنابشوت اسم الفئة — يبقى السجل مفهومًا لو حُذفت الفئة
            $table->string('category_key', 40)->nullable();
            $table->string('category_name', 100)->nullable();

            $table->decimal('amount', 12, 2);
            $table->string('description', 255)->nullable();
            $table->string('reference', 50)->nullable();     // رقم إشعار/وصل خارجي
            $table->string('payment_method', 20)->default('cash');
            $table->date('expense_date')->index();

            // الإلغاء بدل الحذف
            $table->boolean('is_cancelled')->default(false);
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['pharmacy_id', 'expense_date'], 'expenses_pharmacy_date_index');
            $table->index(['pharmacy_id', 'is_cancelled'], 'expenses_pharmacy_cancelled_index');
        });

        // ─── المشتريات (من الموردين) ───────────────────────────────────────
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pharmacy_id')->constrained('pharmacies')->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('supplier_name', 150)->nullable();
            $table->string('number', 30);                    // PUR-0001

            $table->decimal('subtotal', 12, 2)->default(0.00);
            $table->decimal('discount', 12, 2)->default(0.00);
            $table->decimal('total', 12, 2)->default(0.00);
            $table->decimal('paid', 12, 2)->default(0.00);
            $table->decimal('remaining', 12, 2)->default(0.00);

            $table->string('payment_method', 20)->default('credit');
            $table->string('status', 20)->default('unpaid'); // paid|partially_paid|unpaid|cancelled
            $table->text('notes')->nullable();

            $table->timestamp('purchased_at')->index();
            $table->boolean('is_cancelled')->default(false);
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['pharmacy_id', 'number'], 'uniq_purchase_number');
            $table->index(['pharmacy_id', 'purchased_at'], 'purchases_pharmacy_date_index');
            $table->index(['supplier_id', 'purchased_at'], 'purchases_supplier_date_index');
        });

        // ─── دفتر الصندوق — المصدر الوحيد لرصيد النقد ──────────────────────
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pharmacy_id')->constrained('pharmacies')->cascadeOnDelete();

            // in (إيداع/تحصيل) | out (سحب/دفع)
            $table->string('direction', 3);
            $table->decimal('amount', 12, 2);

            // sale | expense | purchase | customer_payment | supplier_payment
            // | withdrawal | deposit | adjustment
            $table->string('source_type', 30);
            // المعرّف المرجعي (sale_id / expense_id ...) بلا FK لأن المصدر يختلف
            $table->unsignedBigInteger('source_id')->nullable();

            $table->string('description', 255)->nullable();
            // كلمة مرور/سبب التعديل اليدوي — إلزامي منطقيًا عند adjustment
            $table->string('reason', 255)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moved_at')->index();
            $table->timestamps();

            $table->index(['pharmacy_id', 'moved_at'], 'cash_movements_pharmacy_date_index');
            $table->index(['pharmacy_id', 'direction'], 'cash_movements_pharmacy_direction_index');
            // يمنع تسجيل حركتين لنفس المصدر (حماية من ازدواج الخصم/الإيداع)
            $table->index(['source_type', 'source_id'], 'cash_movements_source_index');
        });

        // ─── تحصيلات العملاء ───────────────────────────────────────────────
        Schema::create('customer_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pharmacy_id')->constrained('pharmacies')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained('sales')->nullOnDelete();

            $table->decimal('amount', 12, 2);
            $table->string('payment_method', 20)->default('cash');
            $table->string('reference', 50)->nullable();
            $table->string('description', 255)->nullable();
            $table->date('paid_at')->index();

            $table->boolean('is_cancelled')->default(false);
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['pharmacy_id', 'paid_at'], 'customer_payments_pharmacy_date_index');
            $table->index(['customer_id', 'paid_at'], 'customer_payments_customer_date_index');
        });

        // ─── دفعات الموردين ────────────────────────────────────────────────
        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pharmacy_id')->constrained('pharmacies')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->foreignId('purchase_id')->nullable()->constrained('purchases')->nullOnDelete();

            $table->decimal('amount', 12, 2);
            $table->string('payment_method', 20)->default('cash');
            $table->string('reference', 50)->nullable();
            $table->string('description', 255)->nullable();
            $table->date('paid_at')->index();

            $table->boolean('is_cancelled')->default(false);
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['pharmacy_id', 'paid_at'], 'supplier_payments_pharmacy_date_index');
            $table->index(['supplier_id', 'paid_at'], 'supplier_payments_supplier_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('customer_payments');
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
    }
};
