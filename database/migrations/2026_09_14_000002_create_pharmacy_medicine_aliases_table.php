<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مرادفات الأسماء الخاصة بكل صيدلية (pharmacy-specific aliases).
 *
 * الغرض: عندما يؤكّد الصيدلي صراحةً أن "panadol xtra" تعني الدواء X،
 * نحفظ القرار فنصبح قادرين على مطابقة نفس الكتابة تلقائياً في المرات القادمة.
 *
 * قرارات التصميم:
 *  - النطاق صيدلية واحدة (وليس عاماً) — لا نسمح لصيدلية بتغيير سلوك الكتالوج
 *    العام لبقية الصيدليات، وهذا الخيار الأكثر أماناً.
 *  - لا يُحفظ أي مرادف نتيجة مطابقة fuzzy تلقائية — فقط بعد تأكيد صريح.
 *  - القيد الفريد يمنع المرادفات المتعارضة لنفس الصيدلية.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pharmacy_medicine_aliases', function (Blueprint $table) {
            $table->id();

            $table->foreignId('pharmacy_id')->constrained('pharmacies')->cascadeOnDelete();
            $table->foreignId('medicine_id')->constrained('medicines')->cascadeOnDelete();

            // الاسم بعد التطبيع (clean + lowercase) — طول 191 ليبقى داخل حدود الفهرس.
            $table->string('alias', 191);

            $table->timestamps();

            // مرادف واحد لا يشير إلا لدواء واحد لنفس الصيدلية.
            $table->unique(['pharmacy_id', 'alias'], 'unique_pharmacy_alias');

            // للبحث العكسي: كل مرادفات دواء معيّن.
            $table->index(['pharmacy_id', 'medicine_id'], 'pharmacy_aliases_pharmacy_medicine_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacy_medicine_aliases');
    }
};
