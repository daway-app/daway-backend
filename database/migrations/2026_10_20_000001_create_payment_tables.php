<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment phase — manual bank transfer with admin review.
 *
 * Additive only: exact order/delivery behavior is untouched. The reference
 * contract is docs/payments/PAYMENT-PHASE-CONTRACT.md.
 *
 * Money lives on the parent order (`orders.total + orders.delivery_fee`);
 * submissions only record what the patient declared, and the receipt file
 * itself is stored on the private `local` disk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_banks', function (Blueprint $table) {
            $table->id();
            $table->string('bank_name');
            $table->string('account_holder');
            $table->string('account_number');
            $table->string('iban')->nullable();
            $table->string('branch')->nullable();
            $table->text('instructions')->nullable();
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order'], 'payment_banks_active_sort_index');
        });

        Schema::create('order_payment_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();

            // The bank may be deleted later; the snapshot columns below keep
            // the historical destination details intact either way.
            $table->foreignId('bank_id')->nullable()->constrained('payment_banks')->nullOnDelete();

            $table->string('bank_name');
            $table->string('account_holder');
            $table->string('account_number');
            $table->string('iban')->nullable();

            $table->decimal('amount', 10, 2)->default(0.00);
            $table->string('sender_name', 100)->nullable();
            $table->string('transfer_reference', 100)->nullable();
            $table->date('transferred_at')->nullable();

            $table->string('receipt_path');
            $table->string('receipt_original_name');
            $table->string('receipt_mime');
            $table->unsignedBigInteger('receipt_size')->default(0);

            $table->string('status', 30)->default('under_review');
            $table->text('review_note')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status'], 'payment_submissions_order_status_index');
            $table->index(['order_id', 'created_at'], 'payment_submissions_order_created_index');
            $table->index(['status', 'created_at'], 'payment_submissions_status_created_index');
        });

        Schema::create('payment_problems', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('submission_id')->nullable()->constrained('order_payment_submissions')->nullOnDelete();
            $table->string('category', 30);
            $table->text('message');
            $table->string('status', 20)->default('open');
            $table->text('admin_note')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status'], 'payment_problems_order_status_index');
            $table->index(['status', 'created_at'], 'payment_problems_status_created_index');
        });

        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'payment_reviewed_by')) {
                // Same policy as `rider_id` (2026_10_18): an indexed BIGINT is
                // used instead of an ALTER-added FK so SQLite can run the same
                // migration. The reviewer row is never deleted in practice.
                $table->unsignedBigInteger('payment_reviewed_by')->nullable()->after('payment_method');
                $table->index('payment_reviewed_by', 'orders_payment_reviewed_by_index');
            }
            if (! Schema::hasColumn('orders', 'payment_reviewed_at')) {
                $table->timestamp('payment_reviewed_at')->nullable()->after('payment_reviewed_by');
            }
            if (! Schema::hasColumn('orders', 'payment_rejection_reason')) {
                $table->text('payment_rejection_reason')->nullable()->after('payment_reviewed_at');
            }
            if (! Schema::hasColumn('orders', 'payment_problem_reported_at')) {
                $table->timestamp('payment_problem_reported_at')->nullable()->after('payment_rejection_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'payment_problem_reported_at')) {
                $table->dropColumn('payment_problem_reported_at');
            }
            if (Schema::hasColumn('orders', 'payment_rejection_reason')) {
                $table->dropColumn('payment_rejection_reason');
            }
            if (Schema::hasColumn('orders', 'payment_reviewed_at')) {
                $table->dropColumn('payment_reviewed_at');
            }
            if (Schema::hasColumn('orders', 'payment_reviewed_by')) {
                $table->dropIndex('orders_payment_reviewed_by_index');
                $table->dropColumn('payment_reviewed_by');
            }
        });

        Schema::dropIfExists('payment_problems');
        Schema::dropIfExists('order_payment_submissions');
        Schema::dropIfExists('payment_banks');
    }
};
