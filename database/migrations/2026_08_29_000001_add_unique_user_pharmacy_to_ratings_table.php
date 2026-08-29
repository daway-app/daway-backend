<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ratings', function (Blueprint $table) {
            // منع تقييمات مكررة لنفس (مستخدم، صيدلية) — C2
            $table->unique(['user_id', 'pharmacy_id'], 'ratings_user_pharmacy_unique');
        });
    }

    public function down(): void
    {
        Schema::table('ratings', function (Blueprint $table) {
            $table->dropUnique('ratings_user_pharmacy_unique');
        });
    }
};
