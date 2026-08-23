<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pharmacies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('pharmacy_custom_id', 50)->unique();
            $table->string('pharmacy_name', 150)->index();
            $table->text('address')->nullable();
            $table->string('region', 150)->nullable();
            $table->decimal('latitude', 10, 8)->nullable()->index();
            $table->decimal('longitude', 11, 8)->nullable()->index();
            $table->string('phone_number', 20)->nullable();
            $table->string('logo')->nullable();
            $table->decimal('avg_rating', 3, 2)->default(0.00);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('profile_completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacies');
    }
};
