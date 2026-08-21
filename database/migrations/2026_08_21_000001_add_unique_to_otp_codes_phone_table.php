<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            // OTP يُخزن الآن كـ hash (bcrypt) بدلاً من النص الصريح
            $table->string('otp', 255)->change();
            $table->unique('phone');
        });
    }

    public function down(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->dropUnique(['phone']);
            $table->string('otp', 6)->change();
        });
    }
};