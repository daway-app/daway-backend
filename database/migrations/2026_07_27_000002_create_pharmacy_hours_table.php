<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pharmacy_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pharmacy_id')->constrained('pharmacies')->cascadeOnDelete();
            $table->enum('day', ['sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri']);
            $table->time('opening_time');
            $table->time('closing_time');
            $table->timestamps();

            $table->unique(['pharmacy_id', 'day'], 'unique_pharmacy_day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacy_hours');
    }
};
