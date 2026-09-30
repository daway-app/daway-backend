<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🔴 فهرس ساخن: جدولا /pharmacy/medicines و/pharmacy/inventory ينفّذان
 * `WHERE pharmacy_id = ? ORDER BY id DESC` (PharmacyMedicineController::index:81
 * وPharmacyInventoryController::index:73).
 *
 * الفهارس القائمة:
 *   - unique(pharmacy_id, medicine_id)      ← البادئة pharmacy_id مغطّاة، لكن الترتيب لا
 *   - (pharmacy_id, quantity)
 *   - (pharmacy_id, created_at)
 * لا واحد منها يغطّي `ORDER BY id DESC` ⇒ MySQL يلجأ إلى filesort على كل تحميل.
 *
 * الفهرس المركّب (pharmacy_id, id) يجعل القراءة index-only مرتّبة ⇒ إزالة filesort.
 *
 * ⚠️ الـDDL idempotent (حارس hasIndex) — المشروع يعيد تشغيل الهجرات على بيئات متأخرة.
 * ⚠️ يجب اختباره على MySQL حقيقي (SQLite لا يكشف فخاخ فهارس FK — MySQL 1553).
 */
return new class extends Migration
{
    private const INDEX_NAME = 'pharmacy_medicines_pharmacy_id_id_index';

    public function up(): void
    {
        if (! Schema::hasIndex('pharmacy_medicines', self::INDEX_NAME)) {
            Schema::table('pharmacy_medicines', function (Blueprint $table) {
                $table->index(['pharmacy_id', 'id'], self::INDEX_NAME);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('pharmacy_medicines', self::INDEX_NAME)) {
            Schema::table('pharmacy_medicines', function (Blueprint $table) {
                $table->dropIndex(self::INDEX_NAME);
            });
        }
    }
};
