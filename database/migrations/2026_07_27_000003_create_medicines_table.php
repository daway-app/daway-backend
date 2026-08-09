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
            $table->foreignId('active_ingredient_id')->constrained('active_ingredients')->onDelete('cascade');
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->timestamps();

            $table->index(['trade_name', 'active_ingredient_id'], 'idx_search');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medicines');
    }
};
