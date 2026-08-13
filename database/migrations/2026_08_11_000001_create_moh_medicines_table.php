<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moh_medicines', function (Blueprint $table) {
            $table->id();
            $table->string('trade_name', 255);
            $table->string('manufacturer', 255)->nullable();
            $table->string('dosage_form', 255)->nullable();
            $table->string('product_class', 255)->nullable();
            $table->string('origin', 50)->nullable();
            $table->unsignedBigInteger('moh_product_id')->nullable();
            $table->string('generic_name', 255)->nullable();
            $table->decimal('official_price', 10, 2)->nullable();
            $table->string('packaging', 255)->nullable();
            $table->string('company', 255)->nullable();
            $table->string('availability', 50)->nullable();
            $table->unsignedBigInteger('moh_drug_id')->nullable();
            $table->date('price_updated_at')->nullable();
            $table->timestamps();

            $table->index(['trade_name', 'generic_name'], 'idx_moh_search');
            $table->index('moh_product_id');
            $table->index('moh_drug_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moh_medicines');
    }
};
