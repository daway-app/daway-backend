<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * باركود الدواء المحلي بلا سجل في كتالوج وزارة الصحة.
 *
 * ── المشكلة ────────────────────────────────────────────────────────────
 * الهجرة الأصلية (`2026_09_15_000001`) أنشأت `moh_medicine_id` بـ
 * `NOT NULL` بينما `local_medicine_id` اختياري. النتيجة: الباركود لا
 * يمكن ربطه بدواء محلي أنشأته صيدلية **إلا** إذا وُجد له نظير في
 * `moh_medicines` — وهو غير موجود لمعظم الأدوية المحلية والمستوردة
 * غير المسجّلة. أي أن نصف قدرة الجدول كانت معطّلة عمليًا.
 *
 * ── ما تفعله ───────────────────────────────────────────────────────────
 * تجعل `moh_medicine_id` قابلاً للـNULL فقط. لا تحذف صفًا، ولا تغيّر
 * أي قيمة قائمة، ولا تمسّ `barcode` الفريد العالمي.
 *
 * ── لماذا لا تُعدَّل الهجرة الأصلية؟ ──────────────────────────────────
 * لأنها نُفِّذت فعليًا على قواعد قائمة (الإنتاج والنسخ المحلية)؛ تعديلها
 * يُغيّر تاريخ المخطط بلا أثر على من نفّذها، ويُخفي الفرق بين بيئة
 * جديدة وبيئة قائمة. هجرة إصلاحية منفصلة هي الطريقة الآمنة الوحيدة.
 *
 * ── أمان التنفيذ ──────────────────────────────────────────────────────
 *  · idempotent: تفحص قابلية العمود للـNULL أولًا وتخرج فورًا إن كان كذلك.
 *  · تسقط المفتاح الأجنبي قبل `change()` — تعديل عمود يحمل FK يفشل على
 *    MySQL — ثم تعيده بنفس سلوك الحذف السابق (`cascadeOnDelete`)، فلا
 *    يتغيّر أي سلوك قائم.
 *  · `down()` ترفض التراجع إن وُجدت صفوف محلية (moh = NULL)، لأن إعادة
 *    `NOT NULL` كانت ستُفشل الهجرة أو تُفسد البيانات.
 */
return new class extends Migration
{
    private const TABLE = 'medicine_barcodes';

    private const COLUMN = 'moh_medicine_id';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            throw new RuntimeException('جدول medicine_barcodes غير موجود؛ تعذر تطبيق هجرة nullable بأمان.');
        }

        if (! $this->columnExists()) {
            throw new RuntimeException('العمود medicine_barcodes.moh_medicine_id غير موجود.');
        }

        $this->assertColumnType();
        $foreignKey = $this->matchingForeignKey();
        $this->assertNoWrongForeignKey();

        if (! $this->isNullable()) {
            if ($foreignKey !== null) {
                $this->dropForeignKey($foreignKey['name']);
            }

            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->unsignedBigInteger(self::COLUMN)->nullable()->change();
            });
        }

        if ($this->matchingForeignKey() === null) {
            $this->assertNoOrphans();
            $this->addForeignKey();
        }

        $this->assertNullableAndForeignKey();
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! $this->columnExists() || ! $this->isNullable()) {
            return;
        }

        $localOnly = DB::table(self::TABLE)->whereNull(self::COLUMN)->count();

        if ($localOnly > 0) {
            throw new RuntimeException(
                "لا يمكن إعادة NOT NULL: يوجد {$localOnly} باركود محلي بلا سجل MOH. "
                .'احذفها أو اربطها أولًا — التراجع الأعمى يُفقد البيانات.'
            );
        }

        $this->assertNoWrongForeignKey();
        $foreignKey = $this->matchingForeignKey();
        if ($foreignKey !== null) {
            $this->dropForeignKey($foreignKey['name']);
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->unsignedBigInteger(self::COLUMN)->nullable(false)->change();
        });

        $this->assertNoOrphans();
        $this->addForeignKey();
    }

    /**
     * هل العمود يقبل NULL فعلًا في قاعدة البيانات الحالية؟
     *
     * يُقرأ من مخطط القاعدة لا من الكود — لأن الحالة الفعلية قد تختلف بين
     * بيئة أُنشئت حديثًا وأخرى نُفِّذت عليها هجرات متتابعة.
     */
    private function isNullable(): bool
    {
        foreach (Schema::getColumns(self::TABLE) as $column) {
            if (($column['name'] ?? null) === self::COLUMN) {
                return (bool) ($column['nullable'] ?? false);
            }
        }

        return false;
    }

    private function columnExists(): bool
    {
        foreach (Schema::getColumns(self::TABLE) as $column) {
            if (($column['name'] ?? null) === self::COLUMN) {
                return true;
            }
        }

        return false;
    }

    private function assertColumnType(): void
    {
        foreach (Schema::getColumns(self::TABLE) as $column) {
            if (($column['name'] ?? null) !== self::COLUMN) {
                continue;
            }

            $driver = DB::connection()->getDriverName();
            $type = strtolower((string) ($column['type_name'] ?? ''));
            $unsigned = str_contains(strtolower((string) ($column['type'] ?? '')), 'unsigned');
            if (($driver === 'sqlite' ? $type !== 'integer' : $type !== 'bigint' || (! ($column['auto_increment'] ?? false) && ! $unsigned))) {
                throw new RuntimeException('نوع medicine_barcodes.moh_medicine_id غير متوقع؛ يتطلب unsigned BIGINT.');
            }

            return;
        }
    }

    /**
     * إسقاط المفتاح الأجنبي إن وُجد.
     *
     * SQLite (بيئة الاختبار) لا يعطي اسمًا ثابتًا للقيد بعد إعادة بناء
     * الجدول، ولذلك نتحقق من وجود المفتاح قبل الإسقاط بدل رمي استثناء.
     */
    private function dropForeignKey(?string $name): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite' && ($name === null || $name === '')) {
            throw new RuntimeException('تعذر تحديد اسم FK الفعلي على moh_medicine_id؛ لم يُسقط القيد.');
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($name) {
            $table->dropForeign(DB::connection()->getDriverName() === 'sqlite' ? [self::COLUMN] : $name);
        });
    }

    private function addForeignKey(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->foreign(self::COLUMN)
                ->references('id')
                ->on('moh_medicines')
                ->cascadeOnDelete();
        });
    }

    private function matchingForeignKey(): ?array
    {
        foreach (Schema::getForeignKeys(self::TABLE) as $key) {
            if (($key['columns'] ?? []) === [self::COLUMN]
                && ($key['foreign_table'] ?? null) === 'moh_medicines'
                && ($key['foreign_columns'] ?? []) === ['id']
                && strtolower((string) ($key['on_delete'] ?? '')) === 'cascade') {
                return $key;
            }
        }

        return null;
    }

    private function assertNoWrongForeignKey(): void
    {
        foreach (Schema::getForeignKeys(self::TABLE) as $key) {
            $matching = $this->matchingForeignKey();
            if (in_array(self::COLUMN, $key['columns'] ?? [], true)
                && ($matching === null || ($key['name'] ?? null) !== ($matching['name'] ?? null))) {
                throw new RuntimeException('يوجد FK غير متوقع على moh_medicine_id؛ لم يُسقط أو يُستبدل حفاظًا على المخطط والبيانات.');
            }
        }
    }

    private function assertNoOrphans(): void
    {
        $orphans = DB::table(self::TABLE.' as b')
            ->leftJoin('moh_medicines as m', 'm.id', '=', 'b.'.self::COLUMN)
            ->whereNotNull('b.'.self::COLUMN)
            ->whereNull('m.id')
            ->count();

        if ($orphans > 0) {
            throw new RuntimeException("تعذر إنشاء FK إلى moh_medicines.id: توجد {$orphans} قيم يتيمة؛ لم تُحذف أو تُعدّل.");
        }
    }

    private function assertNullableAndForeignKey(): void
    {
        if (! $this->isNullable() || $this->matchingForeignKey() === null) {
            throw new RuntimeException('مخطط medicine_barcodes لم يصل إلى الحالة المتوقعة بعد التنفيذ.');
        }
    }
};
