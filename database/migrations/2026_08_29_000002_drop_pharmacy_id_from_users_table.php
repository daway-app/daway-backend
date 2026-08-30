<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * C6: حذف العمود الميت `users.pharmacy_id` (string) وعلاقة pharmacyByCustomId.
     *
     * السبب:
     *  - العمود كان string وunique بدون FK، يكرر العلاقة المنطقية مع
     *    pharmacies.user_id (الـ FK الحقيقي في 2026_07_27_000001).
     *  - لا يوجد controller ولا endpoint ولا query يستخدمه في الإنتاج.
     *  - وجوده في User::$fillable كان يهدد mass-assignment (تمّت إزالته أيضاً في C1).
     *
     * آمن لأن:
     *  - التحقق التفصيلي على الكود (Grep) لم يُظهر أي قراءة/كتابة فعلية.
     *  - relation pharmacyByCustomId() كان dead code منذ إنشائه.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['pharmacy_id']);
            $table->dropColumn('pharmacy_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pharmacy_id')->unique()->nullable()->after('id');
        });
    }
};
