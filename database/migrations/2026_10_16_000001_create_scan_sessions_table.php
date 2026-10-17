<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جلسات المسح بالهاتف — جدول مستقل تمامًا.
 *
 * ── لماذا جدول جديد لا عمود على جدول قائم؟ ──────────────────────────
 * جلسة المسح **كائن مؤقت** عمره دقائق، ومعدّل إنشائه عالٍ (كل مرة يفتح
 * المستخدم نافذة المسح). وضعه على `pharmacies` أو `users` كان سيلوّث
 * جداول دائمة ببيانات عابرة، ويستلزم كتابةً على صفوف يقرأها التطبيق.
 *
 * ── لماذا `uuid` لا تسلسل؟ ──────────────────────────────────────────
 * `id` التسلسلي **قابل للتعداد**: من يعرف الصيغة يجرّب `1,2,3` ويكتشف
 * جلسات صيدليات أخرى (وقد يُسقط توكنًا بالخطأ). الـuuid يجعل التخمين
 * غير عملي. نفس مبدأ مسار مستند الاستيراد في `routes/web.php`.
 *
 * ── لماذا `pairing_token` مُهشَّر؟ ───────────────────────────────────
 * التوكن سرّ مؤقت يحمله الـQR. لو سُرّب جدول قاعدة البيانات (نسخة احتياطية،
 * سجل استعلامات) لكان نصًّا صريحًا يعني سيطرة على الجلسة. نخزّن `sha256`
 * فقط — والبحث بالمُهشَّر هو المقارنة (لا حاجة إلى `hash_equals`).
 *
 * ── لماذا عمودا `pending_device_*`؟ ─────────────────────────────────
 * عقد الواجهة يقرأ `pending_device{name}`، وفيها تدفّق [سماح]/[رفض] بشري:
 * جهاز ثانٍ يحاول الاقتران **لا يُرفض صامتًا** بل يظهر للمستخدم على الويب
 * ليقرّر. بلا هذين العمودين يتعطّل ذلك التدفّق كليًّا.
 *
 * ⚠️ هذا الجدول لا يمسّ `sales` · `sale_items` · `orders` · `order_items`
 * ولا أي جدول مخزون/محاسبة — علاقته الوحيدة `pharmacies` و`users` للقراءة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('scan_sessions')) {
            Schema::create('scan_sessions', function (Blueprint $table) {
                $table->uuid('id')->primary();

                // العزل الأساسي: كل قراءة/كتابة تُقيَّد بـpharmacy_id في نفس
                // الاستعلام (لا IDOR). الحذف يتتالى مع حذف الصيدلية.
                $table->foreignId('pharmacy_id')->constrained('pharmacies')->cascadeOnDelete();

                // من أنشأ الجلسة (للتدقيق). يُصفَّر بدل حذف الجلسة لو حُذف المستخدم.
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

                // رمز عرض قصير يُكتب يدويًّا كبديل للـQR — فريد لتفادي الالتباس.
                $table->string('pairing_code', 8)->unique();

                // sha256 hex للتوكن المؤقت. النصّ الصريح لا يُخزَّن إطلاقًا.
                // يُستخدم **للالاقتران فقط** — لا لإرسال باركود.
                $table->string('pairing_token', 64)->unique();

                // sha256 hex لتوكن الجهاز المقترن. رمز **منفصل** يُصدره
                // الاقتران ويُستخدم لإرسال الباركود وحده.
                //
                // ── لماذا رمزان لا رمز واحد؟ ─────────────────────────────
                // لو أرسل الجهاز باركوده بتوكن الاقتران نفسه، لكان الـQR
                // المعروض على الشاشة صلاحية إرسال دائمة. الفصل يجعل للـQR
                // قدرة واحدة (الاقتران)، وللجهاز المقترن قدرة أخرى. هذا هو
                // الفرق بين «باب يُفتح مرة» و«باب يبقى مفتوحًا».
                $table->string('device_token', 64)->nullable()->unique();

                // waiting | paired | expired | closed — نصّ لا enum: عُرف
                // المشروع (`sales.status`)، والثوابت في الموديل.
                $table->string('status', 20)->default('waiting');

                // الجهاز النشط الواحد. `active_device_id` هو ما يُقارن به
                // ذرّيًّا لمنع سباق الاقتران المزدوج.
                $table->string('active_device_id', 191)->nullable();
                $table->string('active_device_name', 120)->nullable();

                // جهاز ينتظر قرارًا بشريًّا ([سماح]/[رفض]).
                $table->string('pending_device_id', 191)->nullable();
                $table->string('pending_device_name', 120)->nullable();

                // آخر باركود وصل ولم يُستهلك بعد — تقرأه الواجهة في الاستطلاع.
                $table->string('scanned_barcode', 32)->nullable();

                // القِصر مقصود: جلسة المسح عمرها دقائق لا ساعات.
                $table->timestamp('expires_at')->index();
                $table->timestamp('closed_at')->nullable();

                $table->timestamps();

                // الاستعلام الأكثر تكرارًا: «الجلسة النشطة لهذه الصيدلية».
                $table->index(['pharmacy_id', 'status'], 'scan_sessions_pharmacy_status_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_sessions');
    }
};
