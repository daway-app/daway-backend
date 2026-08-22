<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_inquiries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('pharmacy_id')->constrained('pharmacies')->cascadeOnDelete();
            $table->foreignId('medicine_id')->nullable()->constrained('medicines')->nullOnDelete();
            $table->text('message')->nullable();
            $table->string('status', 20)->default('new');
            $table->timestamps();

            $table->index('pharmacy_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_inquiries');
    }
};
