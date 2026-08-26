<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// الاسم العربي اختياري للدواء — الإنجليزي يبقى الإلزامي (trade_name)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medicines', function (Blueprint $table) {
            $table->string('trade_name_ar', 150)->nullable()->after('trade_name');
            $table->index('trade_name_ar', 'idx_trade_name_ar');
        });
    }

    public function down(): void
    {
        Schema::table('medicines', function (Blueprint $table) {
            $table->dropIndex('idx_trade_name_ar');
            $table->dropColumn('trade_name_ar');
        });
    }
};
