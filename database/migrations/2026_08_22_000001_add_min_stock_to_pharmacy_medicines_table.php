<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pharmacy_medicines', function (Blueprint $table) {
            $table->unsignedInteger('min_stock')->nullable()->after('quantity');
            $table->index('min_stock');
        });
    }

    public function down(): void
    {
        Schema::table('pharmacy_medicines', function (Blueprint $table) {
            $table->dropIndex(['min_stock']);
            $table->dropColumn('min_stock');
        });
    }
};
