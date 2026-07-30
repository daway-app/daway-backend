<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alternatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medicine_id')->constrained('medicines')->cascadeOnDelete();
            $table->foreignId('alternative_medicine_id')->constrained('medicines')->cascadeOnDelete();
            $table->string('notes')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['medicine_id', 'alternative_medicine_id'], 'unique_alternative_link');
        });

        // CHECK constraint - يمنع الدواء من أن يكون بديلاً لنفسه
        DB::statement('ALTER TABLE alternatives ADD CONSTRAINT chk_not_self_alternative CHECK (medicine_id <> alternative_medicine_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('alternatives');
    }
};
