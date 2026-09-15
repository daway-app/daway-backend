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
     */
    public function up(): void
    {
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

        Schema::table('category_medicine_links', function (Blueprint $table) {
            $table->foreignId('subcategory_id')
                ->nullable()
                ->after('category_id')
                ->constrained('subcategories')
                ->cascadeOnDelete();
        });

        // الفهارس الفريدة القديمة تفترض مستوى واحداً (category_id + مفتاح الدواء)،
        // فتمنع نفس الدواء من الظهور في قسمين فرعيين تحت قسم رئيسي واحد. نستبدلها
        // بفهارس تشمل subcategory_id. ملاحظة MySQL: NULL لا يتعارض في الفهارس
        // الفريدة، لذا صفوف المستوى الرئيسي (subcategory_id = NULL) لا تتعارض مع
        // بعضها في MySQL — وهو سلوك مقصود هنا لأن التفرد الحقيقي يحرسه الحقلان
        // الأخيران (moh_product_id / moh_drug_id) مع category_id.
        Schema::table('category_medicine_links', function (Blueprint $table) {
            $table->dropUnique('uniq_cat_moh_product');
            $table->dropUnique('uniq_cat_moh_drug');
            $table->dropUnique('uniq_cat_medicine');
        });

        Schema::table('category_medicine_links', function (Blueprint $table) {
            $table->unique(['category_id', 'subcategory_id', 'moh_product_id'], 'uniq_cat_sub_moh_product');
            $table->unique(['category_id', 'subcategory_id', 'moh_drug_id'], 'uniq_cat_sub_moh_drug');
            $table->unique(['category_id', 'subcategory_id', 'medicine_id'], 'uniq_cat_sub_medicine');
        });
    }

    public function down(): void
    {
        Schema::table('category_medicine_links', function (Blueprint $table) {
            $table->dropUnique('uniq_cat_sub_moh_product');
            $table->dropUnique('uniq_cat_sub_moh_drug');
            $table->dropUnique('uniq_cat_sub_medicine');
        });

        Schema::table('category_medicine_links', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subcategory_id');
        });

        Schema::table('category_medicine_links', function (Blueprint $table) {
            $table->unique(['category_id', 'moh_product_id'], 'uniq_cat_moh_product');
            $table->unique(['category_id', 'moh_drug_id'], 'uniq_cat_moh_drug');
            $table->unique(['category_id', 'medicine_id'], 'uniq_cat_medicine');
        });

        Schema::dropIfExists('subcategories');
    }
};
