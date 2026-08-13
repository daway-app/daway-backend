<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('favoritable_type', 100);
            $table->unsignedBigInteger('favoritable_id');
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'favoritable_type', 'favoritable_id'], 'unique_favorite');
            $table->index(['favoritable_type', 'favoritable_id'], 'idx_favoritable');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favorites');
    }
};
