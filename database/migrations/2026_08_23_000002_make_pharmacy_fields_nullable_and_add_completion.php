<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pharmacies', function (Blueprint $table) {
            if (! Schema::hasColumn('pharmacies', 'region')) {
                $table->string('region', 150)->nullable()->after('address');
            }
            if (! Schema::hasColumn('pharmacies', 'profile_completed_at')) {
                $table->timestamp('profile_completed_at')->nullable()->after('is_active');
            }
        });

        // جعل الحقول الموجودة nullable في MySQL (في البيئات اللي شغّلت Migration الأصلي قبل التعديل)
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE pharmacies MODIFY address TEXT NULL');
            DB::statement('ALTER TABLE pharmacies MODIFY region VARCHAR(150) NULL');
            DB::statement('ALTER TABLE pharmacies MODIFY latitude DECIMAL(10,8) NULL');
            DB::statement('ALTER TABLE pharmacies MODIFY longitude DECIMAL(11,8) NULL');
            DB::statement('ALTER TABLE pharmacies MODIFY phone_number VARCHAR(20) NULL');
            DB::statement('ALTER TABLE pharmacies MODIFY profile_completed_at TIMESTAMP NULL');
        }
    }

    public function down(): void
    {
        Schema::table('pharmacies', function (Blueprint $table) {
            if (Schema::hasColumn('pharmacies', 'region')) {
                $table->dropColumn('region');
            }
            if (Schema::hasColumn('pharmacies', 'profile_completed_at')) {
                $table->dropColumn('profile_completed_at');
            }
        });
    }
};
