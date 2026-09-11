<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ربط التصنيفات بالأدوية عبر مفاتيح مستقرة فقط:
     *  - moh_product_id / moh_drug_id: مفاتيح أعمال مستقرة من كتالوج وزارة الصحة
     *    (moh_medicines.id غير مستقر — moh:import/moh:sync يعملان delete-all ثم insert)
     *  - medicine_id: للأدوية المحلية في جدول medicines
     *
     * الفهارس الفريدة المركبة تمنع duplicate links، والفهارس المنفصلة تخدم
     * استعلامات الربط/الفلترة في الاتجاهين.
     */
    public function up(): void
    {
        Schema::create('category_medicine_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->unsignedBigInteger('moh_product_id')->nullable()->index();
            $table->unsignedBigInteger('moh_drug_id')->nullable()->index();
            $table->foreignId('medicine_id')->nullable()->constrained('medicines')->nullOnDelete()->index();
            $table->string('source', 30)->default('rules'); // product_class | rules | admin
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->boolean('needs_review')->default(false)->index();
            $table->timestamps();

            $table->unique(['category_id', 'moh_product_id'], 'uniq_cat_moh_product');
            $table->unique(['category_id', 'moh_drug_id'], 'uniq_cat_moh_drug');
            $table->unique(['category_id', 'medicine_id'], 'uniq_cat_medicine');
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_medicine_links');
    }
};
