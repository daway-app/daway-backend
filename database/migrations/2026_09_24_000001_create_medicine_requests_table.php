<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medicine_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pharmacy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->string('trade_name', 150);
            $table->string('trade_name_ar', 150)->nullable();
            $table->string('generic_name', 150)->nullable();
            $table->string('manufacturer', 150)->nullable();
            $table->string('active_ingredient', 150)->nullable();
            $table->string('dosage_form', 150)->nullable();
            $table->string('packaging', 150)->nullable();
            $table->string('origin', 50)->nullable();
            $table->string('company', 150)->nullable();
            $table->decimal('official_price', 10, 2)->nullable();
            $table->string('barcode', 64)->nullable();
            $table->string('image')->nullable();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subcategory_id')->nullable()->constrained()->nullOnDelete();
            $table->text('admin_notes')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            // العلاقة الحصرية: عند approval يُملا أحد الحقلين فقط.
            $table->foreignId('approved_moh_medicine_id')->nullable()->constrained('moh_medicines')->nullOnDelete();
            $table->foreignId('approved_medicine_id')->nullable()->constrained('medicines')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['pharmacy_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index('trade_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medicine_requests');
    }
};
