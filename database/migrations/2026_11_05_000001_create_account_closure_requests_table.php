<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * طلبات إغلاق الحسابات + علامة الإغلاق على الحساب نفسه.
 *
 * لماذا جدول طلبات لا عمود حالة فقط:
 *  · إغلاق الصيدلية يحتاج موافقة أدمن ⇒ نحتاج دورة حياة (pending/approved/rejected).
 *  · نحتاج معرفة من طلب ومتى وبأي سبب، ونص قرار المراجع.
 *  · `blockers_at_request` يحفظ صورة الموانع لحظة الطلب — للأدمن وللتدقيق.
 *
 * `closed_at` على الحساب هو المصدر الوحيد للحقيقة عند فحص «هل الحساب مغلق؟»،
 * أما الطلب فهو سجل الإجراء. لا حذف تلقائي لأي سجل مالي في هذه الهجرة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_closure_requests', function (Blueprint $table) {
            $table->id();

            // user (مريض) أو pharmacy
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');

            $table->string('status', 20)->default('pending'); // pending | approved | rejected | cancelled
            $table->string('requested_by_type', 20)->default('owner'); // owner | admin
            $table->unsignedBigInteger('requested_by')->nullable();

            $table->text('reason')->nullable();
            $table->json('blockers_at_request')->nullable();

            $table->text('review_note')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            $table->index(['subject_type', 'subject_id'], 'account_closure_subject_index');
            $table->index(['status', 'created_at'], 'account_closure_status_created_index');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->after('is_active');
        });

        Schema::table('pharmacies', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('pharmacies', function (Blueprint $table) {
            $table->dropColumn('closed_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('closed_at');
        });

        Schema::dropIfExists('account_closure_requests');
    }
};
