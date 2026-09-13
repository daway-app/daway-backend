<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تاريخ تسليم بيانات الدخول للصيدلية (Pharmacy ID + كلمة المرور).
 *
 * null  = لم تُسلّم بعد (الصيدلية مسجّلة بانتظار موافقة الأدمن).
 * timestamp = تُسلّمت (عبر SMS/OTP أو أي قناة أخرى) ويمكنها الدخول.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pharmacies', function (Blueprint $table) {
            if (! Schema::hasColumn('pharmacies', 'delivered_at')) {
                $table->timestamp('delivered_at')->nullable()->after('profile_completed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pharmacies', function (Blueprint $table) {
            if (Schema::hasColumn('pharmacies', 'delivered_at')) {
                $table->dropColumn('delivered_at');
            }
        });
    }
};
