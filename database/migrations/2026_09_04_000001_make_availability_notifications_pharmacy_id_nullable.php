<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * pharmacy_id أصبح اختيارياً في تنبيهات التوفر:
     *  - patient قد يشترك في تنبيه "الدواء X متوفر في أي صيدلية" دون تحديد pharmacy.
     *  - الـunique (user_id, medicine_id, pharmacy_id) يحتاج عموداً nullable ليعمل
     *   بشكل صحيح (MySQL يسمح بعدة NULLs).
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            // SQLite: نحتاج إعادة بناء الجدول لأن DROP COLUMN مع index مركّب
            // ينتج "error in index after drop column".

            DB::statement('PRAGMA foreign_keys=OFF');

            // 1) أنشئ جدولاً جديداً بالـ schema المطلوب.
            DB::statement('CREATE TABLE availability_notifications_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                medicine_id INTEGER NOT NULL,
                pharmacy_id INTEGER NULL,
                is_notified TINYINT(1) NOT NULL DEFAULT 0,
                notified_at TIMESTAMP NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (medicine_id) REFERENCES medicines(id) ON DELETE CASCADE,
                FOREIGN KEY (pharmacy_id) REFERENCES pharmacies(id) ON DELETE SET NULL
            )');

            // 2) أنشئ index للدورة (اسم فريد لتجنب التعارض مع migration سابقة).
            DB::statement('CREATE INDEX availability_notifications_cycle_index_v2 ON availability_notifications_new (user_id, medicine_id, pharmacy_id, notified_at)');

            // 3) انسخ البيانات.
            DB::statement('INSERT INTO availability_notifications_new (id, user_id, medicine_id, pharmacy_id, is_notified, notified_at, created_at, updated_at) SELECT id, user_id, medicine_id, pharmacy_id, is_notified, notified_at, created_at, updated_at FROM availability_notifications');

            // 4) احذف القديم وأعد التسمية.
            DB::statement('DROP TABLE availability_notifications');
            DB::statement('ALTER TABLE availability_notifications_new RENAME TO availability_notifications');

            DB::statement('PRAGMA foreign_keys=ON');

            return;
        }

        Schema::table('availability_notifications', function (Blueprint $table) {
            $table->dropForeign(['pharmacy_id']);
        });
        DB::statement('ALTER TABLE availability_notifications MODIFY pharmacy_id BIGINT UNSIGNED NULL');
        Schema::table('availability_notifications', function (Blueprint $table) {
            $table->foreign('pharmacy_id')
                ->references('id')->on('pharmacies')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys=OFF');

            DB::statement('CREATE TABLE availability_notifications_old (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                medicine_id INTEGER NOT NULL,
                pharmacy_id INTEGER NOT NULL,
                is_notified TINYINT(1) NOT NULL DEFAULT 0,
                notified_at TIMESTAMP NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (medicine_id) REFERENCES medicines(id) ON DELETE CASCADE,
                FOREIGN KEY (pharmacy_id) REFERENCES pharmacies(id) ON DELETE CASCADE
            )');

            DB::statement('INSERT INTO availability_notifications_old (id, user_id, medicine_id, pharmacy_id, is_notified, notified_at, created_at, updated_at) SELECT id, user_id, medicine_id, COALESCE(pharmacy_id, 0), is_notified, notified_at, created_at, updated_at FROM availability_notifications');

            DB::statement('DROP TABLE availability_notifications');
            DB::statement('ALTER TABLE availability_notifications_old RENAME TO availability_notifications');

            DB::statement('PRAGMA foreign_keys=ON');

            return;
        }

        Schema::table('availability_notifications', function (Blueprint $table) {
            $table->dropForeign(['pharmacy_id']);
        });
        DB::statement('DELETE FROM availability_notifications WHERE pharmacy_id IS NULL');
        DB::statement('ALTER TABLE availability_notifications MODIFY pharmacy_id BIGINT UNSIGNED NOT NULL');
        Schema::table('availability_notifications', function (Blueprint $table) {
            $table->foreign('pharmacy_id')
                ->references('id')->on('pharmacies')
                ->cascadeOnDelete();
        });
    }
};
