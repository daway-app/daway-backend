<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_inquiries', function (Blueprint $table) {
            $table->text('reply')->nullable()->after('message');
            $table->enum('availability_status', ['available', 'unavailable', 'low_stock'])->nullable()->after('status');
            $table->timestamp('replied_at')->nullable()->after('availability_status');
        });
    }

    public function down(): void
    {
        Schema::table('patient_inquiries', function (Blueprint $table) {
            $table->dropColumn(['reply', 'availability_status', 'replied_at']);
        });
    }
};
