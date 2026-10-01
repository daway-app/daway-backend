<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جداول الإرجاعات (مرتجعات البيع).
 *
 * ⚠️ هجرة DDL غير idempotent بطبيعتها — لذا نحرسها بـ`hasTable` حتى لا
 * تفشل إعادة التشغيل على بيئة سبق أن طبّقتها (Render أحيانًا يعيد تشغيل
 * الأوامر على حاوية جديدة).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('refunds')) {
            Schema::create('refunds', function (Blueprint $table) {
                $table->id();
                $table->foreignId('pharmacy_id')->constrained('pharmacies')->cascadeOnDelete();
                $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('refunded_at');
                $table->decimal('amount', 12, 2)->default(0.00);
                $table->text('reason')->nullable();
                $table->string('status', 20)->default('pending');
                $table->timestamps();

                $table->index(['pharmacy_id', 'status'], 'refunds_pharmacy_status_index');
                $table->index(['pharmacy_id', 'refunded_at'], 'refunds_pharmacy_refunded_at_index');
            });
        }

        if (! Schema::hasTable('refund_items')) {
            Schema::create('refund_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('refund_id')->constrained('refunds')->cascadeOnDelete();
                $table->foreignId('sale_item_id')->constrained('sale_items')->cascadeOnDelete();
                $table->unsignedInteger('quantity')->default(1);
                $table->decimal('amount', 12, 2)->default(0.00);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_items');
        Schema::dropIfExists('refunds');
    }
};
