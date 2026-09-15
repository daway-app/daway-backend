<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إضافة عمود reason إلى medicine_enrichment_reviews — يُميّز صفوف
 * تعارض الباركود (barcode_conflict) عن المراجعات العادية (low_confidence).
 * backward-compatible: nullable، بلا حذف أو تعديل صفوف موجودة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medicine_enrichment_reviews', function (Blueprint $table) {
            $table->string('reason', 30)->nullable()->after('provider_payload');
            $table->index('reason');
        });
    }

    public function down(): void
    {
        Schema::table('medicine_enrichment_reviews', function (Blueprint $table) {
            $table->dropIndex(['reason']);
            $table->dropColumn('reason');
        });
    }
};
