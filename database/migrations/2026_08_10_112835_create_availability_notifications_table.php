
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('availability_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('medicine_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pharmacy_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_notified')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'medicine_id', 'pharmacy_id'], 'unique_availability_notification');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('availability_notifications');
    }
};
