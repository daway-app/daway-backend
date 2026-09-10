<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_tombstones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pharmacy_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('pharmacy_medicine_id');
            $table->timestamp('deleted_at');
            $table->index(['pharmacy_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_tombstones');
    }
};
