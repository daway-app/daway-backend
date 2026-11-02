<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * بيانات استرجاع إيصال التحويل عند تخزينه على Cloudinary بنوع `authenticated`.
 *
 * لماذا عمودان جديدان بدل إعادة استخدام `transfer_receipt_path` وحده:
 *  · Cloudinary لا يعطي رابطًا دائمًا صالحًا لأصل محمي — يعطي `public_id`،
 *    ونوع المورد (`image`/`raw`) ونوع التسليم (`authenticated`) وهم لازمون
 *    لإعادة بناء طلب التنزيل الموقّع.
 *  · `transfer_receipt_path` يبقى كما هو للصفوف القديمة وللأقراص (local/r2)،
 *    فالقيمة `null` في العمود الجديد تعني «تخزين على قرص» بلا أي ترحيل بيانات.
 *
 * الصفوف الموجودة لا تحتاج تعديلًا: `transfer_receipt_driver = null` تُفسَّر
 * كـ«قرص» داخل ReceiptStorage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settlements', function (Blueprint $table) {
            $table->string('transfer_receipt_driver', 20)->nullable()->after('transfer_receipt_path');
            $table->json('transfer_receipt_meta')->nullable()->after('transfer_receipt_driver');
        });
    }

    public function down(): void
    {
        Schema::table('platform_settlements', function (Blueprint $table) {
            $table->dropColumn(['transfer_receipt_driver', 'transfer_receipt_meta']);
        });
    }
};
