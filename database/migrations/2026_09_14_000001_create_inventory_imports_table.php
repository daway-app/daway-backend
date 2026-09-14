<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جدول جلسات الاستيراد الجماعي للمخزون.
 *
 * لماذا جدول وليس Cache:
 *  - بيئة الاختبار تستخدم CACHE_STORE=array (لا يعبر الطلبات بثبات).
 *  - الإنتاج يستخدم CACHE_STORE=file — غير موثوق للتحقق من عدم التلاعب.
 *  - نحتاج تدقيقاً (auditability) لمعرفة من استورد ماذا ومتى.
 *
 * كل الحالة القابلة للتلاعب (نتائج المعاينة) تُخزَّن هنا كـ JSON،
 * ولا يُقبل أي قرار من الـ frontend إلا بإعادة التحقق منه server-side.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_imports', function (Blueprint $table) {
            $table->id();

            // المعرّف العام المستخدم في الـ URLs/الـ API — لا نكشف id المتسلسل.
            $table->uuid('uuid')->unique();

            $table->foreignId('pharmacy_id')->constrained('pharmacies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // اسم الملف كما رفعه المستخدم — للعرض فقط، لا يُستخدم كمسار إطلاقاً.
            $table->string('original_filename', 255);

            // بصمة الملف (sha256) — لكشف إعادة رفع نفس الملف وللتحقق من السلامة.
            $table->string('file_hash', 64);

            // المسار داخل قرص التخزين (اسم عشوائي مولّد من الخادم — لا نثق باسم المستخدم).
            $table->string('file_path', 255);

            // uploaded | previewed | ready | committed | expired | failed
            $table->string('status', 20)->default('uploaded');

            // عدّادات الملخّص
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('matched_rows')->default(0);
            $table->unsignedInteger('review_rows')->default(0);
            $table->unsignedInteger('unmatched_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->unsignedInteger('committed_rows')->default(0);
            $table->unsignedInteger('created_medicines')->default(0);

            // حالة المعاينة الكاملة (صفوف + قرارات) — مصدر الحقيقة عند الـ commit.
            $table->longText('rows_payload')->nullable();

            // ملخّص نتيجة الـ commit.
            $table->longText('commit_summary')->nullable();

            $table->timestamp('committed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['pharmacy_id', 'status'], 'inventory_imports_pharmacy_status_index');
            $table->index('expires_at', 'inventory_imports_expires_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_imports');
    }
};
