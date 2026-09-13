<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pharmacies', function (Blueprint $table) {
            // كلمة المرور التي اختارها صاحب الصيدلية عند التسجيل الذاتي — مخزّنة
            // مشفّرة (Crypt) وليست hash، لأن المطلوب إرسالها نصاً في رسالة التسليم
            // بعد موافقة الأدمن. تُصفَّر (null) فور التسليم — لا تبقى مخزّنة بعده.
            $table->text('pending_password')->nullable()->after('delivered_at');
        });
    }

    public function down(): void
    {
        Schema::table('pharmacies', function (Blueprint $table) {
            $table->dropColumn('pending_password');
        });
    }
};
