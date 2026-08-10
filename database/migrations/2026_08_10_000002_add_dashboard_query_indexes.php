<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->index(['role', 'created_at'], 'users_role_created_at_index');
            $table->index(['role', 'is_active'], 'users_role_is_active_index');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['user_id', 'is_read'], 'notifications_user_read_index');
            $table->index(['user_id', 'created_at'], 'notifications_user_created_at_index');
        });

        Schema::table('ratings', function (Blueprint $table) {
            $table->index(['pharmacy_id', 'created_at'], 'ratings_pharmacy_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('ratings', function (Blueprint $table) {
            $table->dropIndex('ratings_pharmacy_created_at_index');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_user_read_index');
            $table->dropIndex('notifications_user_created_at_index');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_role_created_at_index');
            $table->dropIndex('users_role_is_active_index');
        });
    }
};
