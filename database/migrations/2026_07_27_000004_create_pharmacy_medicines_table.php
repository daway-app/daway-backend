<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pharmacy_medicines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pharmacy_id')->constrained('pharmacies')->cascadeOnDelete();
            $table->foreignId('medicine_id')->constrained('medicines')->cascadeOnDelete();
            $table->decimal('price', 10, 2)->default(0.00);
            $table->integer('quantity')->default(0);
            $table->boolean('is_available')->default(true)->index();
            $table->timestamps();

            $table->unique(['pharmacy_id', 'medicine_id'], 'unique_pharmacy_medicine');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacy_medicines');
    }
};
