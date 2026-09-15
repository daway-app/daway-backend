<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * أقسام فرعية (subcategories) تحت الأقسام الرئيسية (categories).
     *
     * المثال الفعلي من واجهة الموبايل: قسم "الفيتامينات والمكملات" يقبل فلترة أدق
     * (فيتامينات الشعر / مكملات البروتين ...). لا يمكن تمثيل ذلك بـ categories
     * لأن الأقسام الرئيسية مستوى واحد فقط، ولا بـ dosage_form لأن شكل الدواء
     * (حبوب/شراب) بُعد مستقل تماماً عن الفئة العلاجية.
     *
     * العلاقة: subcategory تنتمي لقسم رئيسي واحد (category_id)، والقسم الرئيسي
     * يحوي عدة أقسام فرعية. الحقل group_key يجمّع الأقسام الفرعية في "مجموعة"
     * لعرضها كصف واحد في الواجهة (مثال: group_key='vitamins' يحوي الشعر/البشرة/...).
     *
     * التصنيف يعتمد المفاتيح المستقرة نفسها في category_medicine_links، لذا
     * الـsubcategory_id يُضاف كعمود على نفس جدول الروابط بدل جدول روابط ثانٍ:
     *  - صف الرابط قد يكون عاماً للقسم الرئيسي (subcategory_id = NULL)
     *  - أو مُقيَّداً بقسم فرعي بعينه (subcategory_id = id)
     * الفهارس الفريدة المركّبة تُحدَّث لتشمل subcategory_id حتى لا يمنع القيد
     * وجود نفس الدواء في قسمين فرعيين تحت نفس القسم الرئيسي.
     *
     * ══════════════════════════════════════════════════════════════════════
     * ملاحظات إصلاح النشر (2026-09-15) — ثلاثة أعطال حقيقية على MySQL:
     * ══════════════════════════════════════════════════════════════════════
     *
     * (١) خطأ 1553 — "Cannot drop index 'uniq_cat_medicine': needed in a
     *     foreign key constraint".
     *     السبب: عمود category_id يحمل FK إلى categories(id). الفهرس
     *     uniq_cat_medicine = (category_id, medicine_id) يبدأ بـ category_id،
     *     فهو الفهرس الداعم الوحيد لذلك الـFK. MySQL يرفض حذف أي فهرس يدعم
     *     قيداً أجنبياً.
     *     الحل: إنشاء الفهارس الجديدة (uniq_cat_sub_*) **قبل** حذف القديمة،
     *     فتصبح الجديدة هي الداعم، ويصير حذف القديمة ممكناً.
     *
     * (٢) خطأ 1050 — "Table 'subcategories' already exists".
     *     السبب: المحاولة الأولى أنشأت الجدول ثم فشلت عند حذف الفهارس،
     *     والجدول بقي (MySQL لا يتراجع عن DDL تلقائياً). إعادة تشغيل الهجرة
     *     تفشل فوراً.
     *     الحل: كل خطوة محروسة بـ hasTable / hasColumn / hasIndex فتصير
     *     الهجرة idempotent وقابلة للتشغيل من أي حالة جزئية.
     *
     * (٣) ترتيب العمليات: كان حذف الفهارس يسبق إضافة العمود الجديد، فالخطأ
     *     يقع في منتصف الطريق. الترتيب الجديد: جدول ← عمود ← فهارس جديدة ←
     *     حذف القديمة.
     *
     * ⚠️ اختبارات SQLite لا تكشف عطلاً كهذا: SQLite لا يفرض القيود الأجنبية
     *    على الفهارس بنفس صرامة MySQL، فالهجرة تمرّ محلياً وتفشل على الإنتاج.
     *    أي تعديل على هذه الهجرة يجب أن يُختبر على MySQL حقيقي.
     */
    public function up(): void
    {
        // ── (١) جدول الأقسام الفرعية — محروس ضد الإنشاء المزدوج ──────────
        if (! Schema::hasTable('subcategories')) {
            Schema::create('subcategories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
                $table->string('name_ar', 150);
                $table->string('name_en', 150);
                $table->string('slug', 180)->unique();
                // مجموعة العرض في الواجهة (نفس مجموعة الفلاتر) — مثال: vitamins | supplements
                $table->string('group_key', 60)->nullable()->index();
                $table->boolean('is_active')->default(true)->index();
                $table->integer('sort_order')->default(0)->index();
                $table->softDeletes();
                $table->timestamps();

                $table->unique(['category_id', 'slug'], 'uniq_subcategory_slug');
            });
        }

        // ── (٢) عمود الربط على نفس جدول الروابط (لا جدول روابط ثانٍ) ──────
        if (! Schema::hasColumn('category_medicine_links', 'subcategory_id')) {
            Schema::table('category_medicine_links', function (Blueprint $table) {
                $table->foreignId('subcategory_id')
                    ->nullable()
                    ->after('category_id')
                    ->constrained('subcategories')
                    ->cascadeOnDelete();
            });
        }

        // ── (٣) الفهارس الجديدة **أولاً** — هذا ما يحلّ خطأ 1553 ──────────
        // الفهارس القديمة تفترض مستوى واحداً (category_id + مفتاح الدواء)،
        // فتمنع نفس الدواء من الظهور في قسمين فرعيين تحت قسم رئيسي واحد.
        // نضيف البدائل التي تشمل subcategory_id أولاً حتى يصبح عندنا فهرس
        // بديل يبدأ بـ category_id، فيصير حذف القديمة ممكناً.
        //
        // ملاحظة MySQL: NULL لا يتعارض في الفهارس الفريدة، لذا صفوف المستوى
        // الرئيسي (subcategory_id = NULL) لا تتعارض مع بعضها — وهو سلوك مقصود
        // هنا لأن التفرد الحقيقي يحرسه الحقلان الأخيران مع category_id.
        $newIndexes = [
            'uniq_cat_sub_moh_product' => ['category_id', 'subcategory_id', 'moh_product_id'],
            'uniq_cat_sub_moh_drug' => ['category_id', 'subcategory_id', 'moh_drug_id'],
            'uniq_cat_sub_medicine' => ['category_id', 'subcategory_id', 'medicine_id'],
        ];

        foreach ($newIndexes as $name => $columns) {
            if (! Schema::hasIndex('category_medicine_links', $name)) {
                Schema::table('category_medicine_links', function (Blueprint $table) use ($columns, $name) {
                    $table->unique($columns, $name);
                });
            }
        }

        // ── (٤) الآن نحذف الفهارس القديمة — آمن لأن البديل أصبح موجوداً ───
        foreach (['uniq_cat_moh_product', 'uniq_cat_moh_drug', 'uniq_cat_medicine'] as $name) {
            if (Schema::hasIndex('category_medicine_links', $name)) {
                Schema::table('category_medicine_links', function (Blueprint $table) use ($name) {
                    $table->dropUnique($name);
                });
            }
        }
    }

    public function down(): void
    {
        // نعيد الفهارس القديمة أولاً، ثم نحذف الجديدة، ثم العمود، ثم الجدول.
        // نفس منطق الترتيب: لا تحذف فهرساً قبل توفّر بديل يدعم الـFK.
        if (Schema::hasTable('category_medicine_links')) {
            $oldIndexes = [
                'uniq_cat_moh_product' => ['category_id', 'moh_product_id'],
                'uniq_cat_moh_drug' => ['category_id', 'moh_drug_id'],
                'uniq_cat_medicine' => ['category_id', 'medicine_id'],
            ];

            foreach ($oldIndexes as $name => $columns) {
                if (! Schema::hasIndex('category_medicine_links', $name)) {
                    Schema::table('category_medicine_links', function (Blueprint $table) use ($columns, $name) {
                        $table->unique($columns, $name);
                    });
                }
            }

            foreach (['uniq_cat_sub_moh_product', 'uniq_cat_sub_moh_drug', 'uniq_cat_sub_medicine'] as $name) {
                if (Schema::hasIndex('category_medicine_links', $name)) {
                    Schema::table('category_medicine_links', function (Blueprint $table) use ($name) {
                        $table->dropUnique($name);
                    });
                }
            }

            if (Schema::hasColumn('category_medicine_links', 'subcategory_id')) {
                // dropConstrainedForeignId = dropForeign + dropColumn.
                // نفصلهما لضمان أن فشل خطوة لا يترك العمود معلّقاً، ونحرّس
                // حذف القيد الأجنبي بالاسم الفعلي الذي تولّده Laravel.
                try {
                    Schema::table('category_medicine_links', function (Blueprint $table) {
                        $table->dropForeign(['subcategory_id']);
                    });
                } catch (Throwable $e) {
                    // القيد غير موجود (حالة جزئية) — نتجاهل ونكمل لحذف العمود.
                }

                if (Schema::hasColumn('category_medicine_links', 'subcategory_id')) {
                    Schema::table('category_medicine_links', function (Blueprint $table) {
                        $table->dropColumn('subcategory_id');
                    });
                }
            }
        }

        Schema::dropIfExists('subcategories');
    }
};
