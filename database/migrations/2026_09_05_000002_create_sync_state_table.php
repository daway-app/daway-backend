<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_state', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('entity');
            $table->timestamp('last_pulled_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'entity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_state');
    }
};
