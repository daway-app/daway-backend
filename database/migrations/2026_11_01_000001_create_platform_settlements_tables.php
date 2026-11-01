<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pharmacy_id')->nullable()->constrained()->nullOnDelete();
            $table->string('pharmacy_name');
            $table->date('period_start');
            $table->date('period_end');
            $table->char('currency', 3)->default('ILS');
            $table->decimal('orders_total', 12, 2)->default(0);
            $table->decimal('adjustments_total', 12, 2)->default(0);
            $table->decimal('net_total', 12, 2)->default(0);
            $table->string('status', 20)->default('draft');
            $table->string('transfer_reference', 120)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['pharmacy_id', 'period_start', 'period_end'], 'platform_settlements_pharmacy_period_unique');
            $table->index(['status', 'period_start'], 'platform_settlements_status_period_index');
        });

        Schema::create('platform_settlement_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained('platform_settlements')->cascadeOnDelete();
            $table->foreignId('sub_order_id')->nullable()->constrained('sub_orders')->nullOnDelete();
            $table->unsignedBigInteger('sub_order_id_snapshot');
            $table->unsignedBigInteger('order_id_snapshot');
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            $table->unique('sub_order_id', 'platform_settlement_items_sub_order_unique');
            $table->index(['settlement_id', 'order_id_snapshot'], 'platform_settlement_items_order_index');
        });

        Schema::create('platform_settlement_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pharmacy_id')->nullable()->constrained()->nullOnDelete();
            $table->string('pharmacy_name');
            $table->date('effective_period_start')->index();
            $table->decimal('amount', 12, 2);
            $table->string('reason', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('settlement_id')->nullable()->constrained('platform_settlements')->nullOnDelete();
            $table->timestamps();

            $table->index(['pharmacy_id', 'settlement_id', 'effective_period_start'], 'platform_adjustments_pharmacy_settlement_period_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settlement_adjustments');
        Schema::dropIfExists('platform_settlement_items');
        Schema::dropIfExists('platform_settlements');
    }
};
