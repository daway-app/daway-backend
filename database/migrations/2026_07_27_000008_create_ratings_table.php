<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('pharmacy_id')->constrained('pharmacies')->cascadeOnDelete();
            $table->unsignedTinyInteger('stars_rating');
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        // CHECK constraint - يحصر القيمة بين 1 و5
        DB::statement('ALTER TABLE ratings ADD CONSTRAINT chk_stars_rating_range CHECK (stars_rating BETWEEN 1 AND 5)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ratings');
    }
};
