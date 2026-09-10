<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // M-19: hot lookup للـ restock observer (medicine_id + pharmacy_id + is_notified)
        Schema::table('availability_notifications', function (Blueprint $table) {
            $table->index(
                ['medicine_id', 'pharmacy_id', 'is_notified'],
                'availability_notifications_observer_lookup_index'
            );
        });

        // M-24: dashboard chart — last 7 days counts لكل صيدلية
        Schema::table('pharmacy_medicines', function (Blueprint $table) {
            $table->index(
                ['pharmacy_id', 'created_at'],
                'pharmacy_medicines_pharmacy_created_at_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('availability_notifications', function (Blueprint $table) {
            $table->dropIndex('availability_notifications_observer_lookup_index');
        });

        Schema::table('pharmacy_medicines', function (Blueprint $table) {
            $table->dropIndex('pharmacy_medicines_pharmacy_created_at_index');
        });
    }
};
