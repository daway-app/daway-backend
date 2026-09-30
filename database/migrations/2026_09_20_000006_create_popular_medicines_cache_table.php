<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('popular_medicines_cache', function (Blueprint $table) {
            $table->id();
            $table->foreignId('moh_medicine_id')->constrained('moh_medicines')->cascadeOnDelete();
            $table->unsignedInteger('search_count');
            $table->timestamp('calculated_at')->useCurrent();

            $table->unique('moh_medicine_id');
            $table->index(['search_count', 'moh_medicine_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('popular_medicines_cache');
    }
};