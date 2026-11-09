<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_closure_requests', function (Blueprint $table) {
            $table->boolean('owner_was_active')->nullable();
            $table->unsignedBigInteger('closed_owner_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('account_closure_requests', function (Blueprint $table) {
            $table->dropColumn(['owner_was_active', 'closed_owner_id']);
        });
    }
};
