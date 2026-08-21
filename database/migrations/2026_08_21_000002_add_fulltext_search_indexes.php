<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * فهارس FULLTEXT لدعم البحث في الكتالوج العام وكتالوج وزارة الصحة.
     * MySQL فقط — sqlite (بيئة الاختبارات) لا يتجاوب معها.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('moh_medicines', function (Blueprint $table) {
            $table->fullText(['trade_name', 'generic_name', 'manufacturer', 'company'], 'ft_moh_search');
            $table->fullText(['trade_name', 'generic_name'], 'ft_moh_tg_search');
        });

        Schema::table('medicines', function (Blueprint $table) {
            $table->fullText(['trade_name', 'active_ingredient'], 'ft_med_search');
            $table->fullText(['active_ingredient'], 'ft_med_active_search');
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('moh_medicines', function (Blueprint $table) {
            $table->dropIndex('ft_moh_search');
            $table->dropIndex('ft_moh_tg_search');
        });

        Schema::table('medicines', function (Blueprint $table) {
            $table->dropIndex('ft_med_search');
            $table->dropIndex('ft_med_active_search');
        });
    }
};