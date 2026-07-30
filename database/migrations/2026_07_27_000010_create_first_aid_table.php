<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('first_aid', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150)->index('idx_title');
            $table->string('category', 100);
            $table->longText('instructions_steps');
            $table->string('image_icon')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('first_aid');
    }
};
