<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medicines', function (Blueprint $table) {
            $table->id();
            $table->string('trade_name', 150);
            $table->string('active_ingredient', 150);
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->boolean('is_available')->default(true);
            $table->integer('stock')->default(0);
            $table->timestamps();

            $table->index(['trade_name', 'active_ingredient'], 'idx_search');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medicines');
    }
};
