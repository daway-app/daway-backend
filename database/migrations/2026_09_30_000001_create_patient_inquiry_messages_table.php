<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_inquiry_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_inquiry_id')->constrained('patient_inquiries')->cascadeOnDelete();
            $table->foreignId('sender_user_id')->constrained('users')->cascadeOnDelete();
            $table->text('message');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['patient_inquiry_id', 'created_at']);
            $table->index(['patient_inquiry_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_inquiry_messages');
    }
};
