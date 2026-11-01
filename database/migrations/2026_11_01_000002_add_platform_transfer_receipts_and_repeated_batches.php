<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settlements', function (Blueprint $table) {
            $table->dropUnique('platform_settlements_pharmacy_period_unique');
            $table->index(['pharmacy_id', 'period_start', 'period_end'], 'platform_settlements_pharmacy_period_index');
            $table->string('transfer_receipt_path')->nullable();
            $table->string('transfer_receipt_original_name')->nullable();
            $table->string('transfer_receipt_mime', 100)->nullable();
            $table->unsignedInteger('transfer_receipt_size')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('platform_settlements', function (Blueprint $table) {
            $table->dropIndex('platform_settlements_pharmacy_period_index');
            $table->dropColumn([
                'transfer_receipt_path',
                'transfer_receipt_original_name',
                'transfer_receipt_mime',
                'transfer_receipt_size',
            ]);
            $table->unique(['pharmacy_id', 'period_start', 'period_end'], 'platform_settlements_pharmacy_period_unique');
        });
    }
};
