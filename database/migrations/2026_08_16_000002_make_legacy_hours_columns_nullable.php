<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * الأعمدة القديمة (opening_time/closing_time) كانت NOT NULL بلا default،
     * وأي insert جديد يملأ open_time/close_time فقط كان يفشل عليها.
     * هسه صارت nullable.
     */
    public function up(): void
    {
        Schema::table('pharmacy_hours', function (Blueprint $table) {
            $table->time('opening_time')->nullable()->change();
            $table->time('closing_time')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('pharmacy_hours', function (Blueprint $table) {
            $table->time('opening_time')->nullable(false)->change();
            $table->time('closing_time')->nullable(false)->change();
        });
    }
};
