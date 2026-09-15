<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * محاسبة الصيدلية — الجزء الأول: العملاء والموردون.
 *
 * ══════════════════════════════════════════════════════════════════════════
 * قرارات معمارية مثبّتة (لا تُغيَّر بلا سبب):
 * ══════════════════════════════════════════════════════════════════════════
 *
 * (١) الملكية عبر `pharmacies.id` فقط — نفس نمط كل جداول الصيدلية القائمة
 *     (`pharmacy_medicines`, `pharmacy_hours`, `ratings`). الحلّ عبر
 *     `App\Services\PharmacyContext::forUser($user)` ولا يُكرَّر في أي مكان.
 *
 * (٢) `cascadeOnDelete` على pharmacy_id: حذف الصيدلية يحذف دفاترها. هذا صحيح
 *     محاسبيًا — لا معنى لفاتورة بلا صيدلية، وإبقاؤها يخلق بيانات يتيمة.
 *
 * (٣) `deleted_at` (SoftDeletes) على العملاء والموردين فقط — لأنهم كيانات
 *     مرجعية تُشار إليها من فواتير قديمة. **لا soft delete على الفواتير**:
 *     الفاتورة المحاسبية تُلغى (`status = 'cancelled'`) ولا تُحذف، وهذا يعطي
 *     مسارًا تدقيقيًا سليمًا (audit trail) بدل اختفاء صامت.
 *
 * (٤) الأرصدة (`balance`) لا تُخزَّن كعمود محسوب مسبقًا إلا حيث يلزم:
 *     `current_balance` على العميل/المورد يُحدَّث **داخل transaction** عند كل
 *     فاتورة/دفعة، وهو مشتق قابل لإعادة الحساب من `customer_payments` +
 *     الفواتير الآجلة. القيمة المخزّنة تُوفّر جمعًا متكررًا ولا تُخالف المصدر.
 *
 * (٥) `phone` ليس فريدًا عالميًا: نفس الرقم قد يكون عميلًا لموردين مختلفين
 *     (والأخ DOB من الصيدليات). التفرد مركّب `(pharmacy_id, phone)`.
 */

return new class extends Migration
{
    public function up(): void
    {
        // ─── العملاء ────────────────────────────────────────────────────────
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pharmacy_id')->constrained('pharmacies')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('phone', 25)->nullable();
            $table->string('email', 150)->nullable();
            $table->text('notes')->nullable();
            // حد الائتمان: 0 = بلا حد (يُسمح بأي مبلغ آجل)
            $table->decimal('credit_limit', 12, 2)->default(0.00);
            // الرصيد الحالي: موجب = العميل مدين لنا (له علينا سالب)
            $table->decimal('current_balance', 12, 2)->default(0.00);
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            // البحث بالاسم شائع في شاشة الفواتير؛ الرقم أقل شيوعًا لكن يُفلتر به
            $table->index(['pharmacy_id', 'name'], 'customers_pharmacy_name_index');
            $table->index(['pharmacy_id', 'is_active'], 'customers_pharmacy_active_index');
            // نفس الرقم لا يُسجَّل مرتين لنفس الصيدلية (حماية من الإدخال المزدوج).
            // NULL مسموح ومتكرر: SQL لا يعتبر NULL متعارضًا — وهو مقصود لمن لا رقم له.
            $table->unique(['pharmacy_id', 'phone'], 'uniq_customer_phone');
        });

        // ─── الموردون ───────────────────────────────────────────────────────
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pharmacy_id')->constrained('pharmacies')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('phone', 25)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('company', 150)->nullable();
            $table->text('notes')->nullable();
            // الرصيد: موجب = نحن مدينون للمورد
            $table->decimal('current_balance', 12, 2)->default(0.00);
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['pharmacy_id', 'name'], 'suppliers_pharmacy_name_index');
            $table->index(['pharmacy_id', 'is_active'], 'suppliers_pharmacy_active_index');
            $table->unique(['pharmacy_id', 'phone'], 'uniq_supplier_phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('customers');
    }
};
