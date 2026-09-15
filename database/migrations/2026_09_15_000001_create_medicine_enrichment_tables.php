<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جداول rnrichment — بلا أكثر من الإضافة، بلا تغيير أي بيانات موجودة.
 *
 * قرار الربط (من الواقع):
 *  - المنتج الرئيسي في Daway هو moh_medicines (17,295) بمفاتيح مستقرة
 *    moh_product_id/moh_drug_id؛ الباركود والصورة يُرفقان عليه.
 *  - local_medicine_id اختياري (nullable) للمطابقة مع كتالوج medcines المحلي.
 *  - barcode **unique عالمياً**: نفس المنتج الفيزيائي لا يُربط بدوائين —
 *    (الازدواجية يُكشف في review وليس silently).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------------- medicine_barcodes ----------------
        Schema::create('medicine_barcodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('moh_medicine_id')->constrained('moh_medicines')->cascadeOnDelete();
            $table->foreignId('local_medicine_id')->nullable()->constrained('medicines')->cascadeOnDelete();
            // القيمة كما جاءت من المصدر (هذه الحقيقة) — النسخة المكيفة تحجب الخطأ
            $table->string('barcode_raw', 64)->nullable();
            $table->string('barcode', 32);
            $table->string('barcode_type', 20)->default('EAN13');
            $table->string('source', 60);
            $table->string('source_reference', 255)->nullable();
            $table->decimal('confidence', 4, 3)->default(0);
            $table->boolean('is_verified')->default(false);
            $table->timestamps();
            $table->unique('barcode', 'uniq_medicine_barcodes_barcode');
            $table->index('source');
            $table->index('moh_medicine_id');
            $table->index('local_medicine_id');
        });

        // ---------------- medicine_images ----------------
        Schema::create('medicine_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('moh_medicine_id')->constrained('moh_medicines')->cascadeOnDelete();
            $table->foreignId('local_medicine_id')->nullable()->constrained('medicines')->cascadeOnDelete();
            $table->string('image_url', 500);
            $table->string('image_type', 40)->default('packshot');
            $table->string('source', 60);
            $table->string('source_reference', 255)->nullable();
            $table->boolean('is_verified')->default(false);
            $table->timestamps();
            $table->unique(['moh_medicine_id', 'image_url'], 'uniq_medicine_images_url');
            $table->index('source');
            $table->index('local_medicine_id');
        });

        // ---------------- medicine_enrichment_runs ----------------
        // مصدر الحقيقة لحالة التشغيل (Resume) — لا CACHE.
        Schema::create('medicine_enrichment_runs', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 60);
            $table->string('status', 20)->default('running'); // running|completed|aborted
            $table->unsignedInteger('batch_size')->default(100);
            $table->unsignedInteger('total_records')->default(0);
            $table->unsignedInteger('processed_records')->default(0);
            $table->unsignedInteger('matched_records')->default(0);
            $table->unsignedInteger('barcode_matches')->default(0);
            $table->unsignedInteger('image_matches')->default(0);
            $table->unsignedInteger('review_records')->default(0);
            $table->unsignedInteger('failed_records')->default(0);
            $table->unsignedBigInteger('last_offset')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        // ---------------- medicine_enrichment_reviews ----------------
        Schema::create('medicine_enrichment_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('moh_medicine_id')->constrained('moh_medicines')->cascadeOnDelete();
            $table->string('provider', 60);
            $table->json('provider_payload')->nullable();
            $table->string('source_reference', 255)->nullable();
            $table->decimal('confidence', 4, 3)->default(0);
            $table->string('status', 20)->default('pending'); // pending|approved|rejected
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index('status');
            $table->index('moh_medicine_id');
        });
    }

    public function down(): void
    {
        // ترتيب عكسي بلا قفل على كتالوج الرئيسي.
        Schema::dropIfExists('medicine_enrichment_reviews');
        Schema::dropIfExists('medicine_enrichment_runs');
        Schema::dropIfExists('medicine_images');
        Schema::dropIfExists('medicine_barcodes');
    }
};
