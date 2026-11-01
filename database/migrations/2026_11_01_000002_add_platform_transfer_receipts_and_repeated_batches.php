<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1) Add the replacement index FIRST. `pharmacy_id` is an FK column and
        //    MySQL keeps using `platform_settlements_pharmacy_period_unique`
        //    (its leftmost column is pharmacy_id) to satisfy that constraint.
        //    Dropping the unique before another usable index exists fails with
        //    SQLSTATE 1553 "Cannot drop index ... needed in a foreign key constraint".
        Schema::table('platform_settlements', function (Blueprint $table) {
            $table->index(['pharmacy_id', 'period_start', 'period_end'], 'platform_settlements_pharmacy_period_index');
        });

        // 2) Now the FK has a fallback index, so the unique can be dropped and
        //    repeated batches per pharmacy/period become possible.
        Schema::table('platform_settlements', function (Blueprint $table) {
            $table->dropUnique('platform_settlements_pharmacy_period_unique');
            $table->string('transfer_receipt_path')->nullable();
            $table->string('transfer_receipt_original_name')->nullable();
            $table->string('transfer_receipt_mime', 100)->nullable();
            $table->unsignedInteger('transfer_receipt_size')->nullable();
        });
    }

    public function down(): void
    {
        // Same ordering rule in reverse: restore the unique index (which the FK
        // can lean on) before dropping the plain index.
        Schema::table('platform_settlements', function (Blueprint $table) {
            $table->unique(['pharmacy_id', 'period_start', 'period_end'], 'platform_settlements_pharmacy_period_unique');
        });

        Schema::table('platform_settlements', function (Blueprint $table) {
            $table->dropIndex('platform_settlements_pharmacy_period_index');
            $table->dropColumn([
                'transfer_receipt_path',
                'transfer_receipt_original_name',
                'transfer_receipt_mime',
                'transfer_receipt_size',
            ]);
        });
    }
};
