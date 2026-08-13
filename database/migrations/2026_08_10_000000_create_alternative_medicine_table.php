<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alternative_medicine', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medicine_id')->constrained('medicines')->cascadeOnDelete();
            $table->foreignId('alternative_id')->constrained('medicines')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['medicine_id', 'alternative_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alternative_medicine');
    }
};
