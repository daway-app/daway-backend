<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * C2: منع تقييمات مكررة لنفس (مستخدم، صيدلية).
     * تقييم واحد فقط لكل زوج user_id × pharmacy_id.
     */
    public function up(): void
    {
        Schema::table('ratings', function (Blueprint $table) {
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
