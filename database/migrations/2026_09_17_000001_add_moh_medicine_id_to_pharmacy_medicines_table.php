<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pharmacy_medicines', function (Blueprint $table) {
            $table->unsignedBigInteger('moh_medicine_id')->nullable()->index()->after('medicine_id');
            $table->foreign('moh_medicine_id')
                ->references('id')
                ->on('moh_medicines')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('pharmacy_medicines', function (Blueprint $table) {
            $table->dropForeign(['moh_medicine_id']);
            $table->dropIndex(['moh_medicine_id']);
            $table->dropColumn('moh_medicine_id');
        });
    }
};