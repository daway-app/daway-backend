<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('terms_accepted')->default(false)->after('notifications_enabled');
            $table->timestamp('terms_accepted_at')->nullable()->after('terms_accepted');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['terms_accepted', 'terms_accepted_at']);
        });
    }
};