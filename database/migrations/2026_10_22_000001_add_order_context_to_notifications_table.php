<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * سياق الطلب في جدول الإشعارات (Phase F1: تحصين idempotency).
 *
 * ── لماذا أُعيدت كتابتها؟ ──────────────────────────────────────────
 * النسخة السابقة أضافت الأعمدة والفهارس **بلا أي حارس**. أي تشغيل جزئي
 * (انقطاع اتصال أثناء الهجرة، إعادة محاولة النشر، أو قاعدة أُنشئت من نسخة
 * تحتوي بعض الأعمدة) كان يفشل بـ "Duplicate column name" ويترك المخطط نصف
 * مهاجَر — ولا يمكن إصلاحه إلا يدويًا.
 *
 * كل خطوة الآن مشروطة بفحص المخطط الفعلي:
 *  · العمود يُضاف فقط إن كان غائبًا.
 *  · الفهرس/القيد الفريد يُضاف فقط إن كان غائبًا (بالاسم، لا بالافتراض).
 *  · `down()` يتحقق قبل كل إسقاط ⇒ تراجع آمن حتى على مخطط جزئي.
 */
return new class extends Migration
{
    private const TABLE = 'notifications';

    private const DEDUPE_UNIQUE = 'notifications_dedupe_key_unique';

    private const READ_INDEX = 'notifications_user_read_created_index';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            throw new RuntimeException('جدول notifications غير موجود؛ لم تُسجّل الهجرة كمكتملة.');
        }

        if (! Schema::hasColumn(self::TABLE, 'order_id')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->foreignId('order_id')
                    ->nullable()
                    ->after('medicine_id')
                    ->constrained('orders')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn(self::TABLE, 'sub_order_id')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->foreignId('sub_order_id')
                    ->nullable()
                    ->after('order_id')
                    ->constrained('sub_orders')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn(self::TABLE, 'dedupe_key')) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->string('dedupe_key', 191)->nullable()->after('type');
            });
        }

        $this->assertColumn('order_id', 'bigint', true);
        $this->assertColumn('sub_order_id', 'bigint', true);
        $this->assertColumn('dedupe_key', 'varchar', true, 191);
        $this->ensureForeignKey('order_id', 'orders');
        $this->ensureForeignKey('sub_order_id', 'sub_orders');

        // القيد الفريد منفصل عن إنشاء العمود: قد يوجد العمود بلا قيد
        // (تشغيل جزئي سابق)، ولا نريد إعادة إنشائه.
        $this->ensureIndex(self::DEDUPE_UNIQUE, ['dedupe_key'], true);
        $this->ensureIndex(self::READ_INDEX, ['user_id', 'is_read', 'created_at'], false);
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $this->dropIndexIfExact(self::READ_INDEX, ['user_id', 'is_read', 'created_at'], false);
        $this->dropIndexIfExact(self::DEDUPE_UNIQUE, ['dedupe_key'], true);

        foreach (['sub_order_id' => 'sub_orders', 'order_id' => 'orders'] as $column => $target) {
            if (Schema::hasColumn(self::TABLE, $column)) {
                $foreign = $this->foreignKey($column, $target);
                if ($foreign !== null) {
                    Schema::table(self::TABLE, function (Blueprint $table) use ($foreign): void {
                        $table->dropForeign($foreign['name']);
                    });
                }

                $this->assertNoForeignKeyOn($column);
                Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropColumn($column));
            }
        }

        if (Schema::hasColumn(self::TABLE, 'dedupe_key')) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropColumn('dedupe_key'));
        }
    }

    private function assertColumn(string $name, string $type, bool $nullable, ?int $length = null): void
    {
        $driver = DB::connection()->getDriverName();
        foreach (Schema::getColumns(self::TABLE) as $column) {
            if (($column['name'] ?? null) !== $name) {
                continue;
            }

            $actualType = strtolower((string) ($column['type_name'] ?? ''));
            $unsigned = str_contains(strtolower((string) ($column['type'] ?? '')), 'unsigned');
            $expectedType = $driver === 'sqlite' && $type === 'bigint' ? 'integer' : $type;
            if ($actualType !== $expectedType || (bool) ($column['nullable'] ?? false) !== $nullable
                || ($driver !== 'sqlite' && in_array($name, ['order_id', 'sub_order_id'], true) && ! $unsigned)
                || ($driver !== 'sqlite' && $length !== null && (int) ($column['length'] ?? 0) !== $length)) {
                throw new RuntimeException("تعريف notifications.{$name} غير متوقع؛ أوقفنا الهجرة دون تعديل البيانات.");
            }

            return;
        }

        throw new RuntimeException("العمود notifications.{$name} مفقود بعد الإضافة.");
    }

    private function ensureForeignKey(string $column, string $target): void
    {
        $existing = array_values(array_filter(Schema::getForeignKeys(self::TABLE), fn (array $key): bool =>
            in_array($column, $key['columns'] ?? [], true)
        ));
        $matching = $this->foreignKey($column, $target);

        foreach ($existing as $key) {
            if ($key !== $matching) {
                throw new RuntimeException("يوجد FK غير متوقع على notifications.{$column}؛ لم يتم استبداله.");
            }
        }

        if ($matching !== null) {
            return;
        }

        $orphans = DB::table(self::TABLE.' as n')
            ->leftJoin($target.' as t', 't.id', '=', 'n.'.$column)
            ->whereNotNull('n.'.$column)->whereNull('t.id')->count();
        if ($orphans > 0) {
            throw new RuntimeException("تعذر ربط notifications.{$column}: توجد {$orphans} مراجع يتيمة؛ لم تُحذف أو تُعدّل.");
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($column, $target): void {
            $table->foreign($column)->references('id')->on($target)->nullOnDelete();
        });

        if ($this->foreignKey($column, $target) === null) {
            throw new RuntimeException("لم يُنشأ FK المتوقع على notifications.{$column}.");
        }
    }

    private function foreignKey(string $column, string $target): ?array
    {
        foreach (Schema::getForeignKeys(self::TABLE) as $key) {
            if (($key['columns'] ?? []) === [$column] && ($key['foreign_table'] ?? null) === $target
                && ($key['foreign_columns'] ?? []) === ['id'] && strtolower((string) ($key['on_delete'] ?? '')) === 'set null') {
                return $key;
            }
        }
        return null;
    }

    private function assertNoForeignKeyOn(string $column): void
    {
        foreach (Schema::getForeignKeys(self::TABLE) as $key) {
            if (in_array($column, $key['columns'] ?? [], true)) {
                throw new RuntimeException("FK غير متوقع على notifications.{$column}؛ أوقفنا rollback حفاظًا على المخطط.");
            }
        }
    }

    private function ensureIndex(string $name, array $columns, bool $unique): void
    {
        $indexes = Schema::getIndexes(self::TABLE);
        foreach ($indexes as $index) {
            if (($index['name'] ?? null) === $name && (($index['columns'] ?? []) !== $columns || (bool) ($index['unique'] ?? false) !== $unique)) {
                throw new RuntimeException("الفهرس {$name} موجود بتعريف خاطئ؛ لم يُستبدل.");
            }
            if (($index['columns'] ?? []) === $columns && (bool) ($index['unique'] ?? false) === $unique) {
                return;
            }
        }

        if ($unique) {
            $duplicates = DB::table(self::TABLE)->select('dedupe_key')->whereNotNull('dedupe_key')
                ->groupBy('dedupe_key')->havingRaw('COUNT(*) > 1')->count();
            if ($duplicates > 0) {
                throw new RuntimeException("تعذر إنشاء {$name}: توجد {$duplicates} قيم dedupe_key مكررة؛ لم تُعدّل الإشعارات.");
            }
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($name, $columns, $unique): void {
            $unique ? $table->unique($columns, $name) : $table->index($columns, $name);
        });
    }

    private function dropIndexIfExact(string $name, array $columns, bool $unique): void
    {
        foreach (Schema::getIndexes(self::TABLE) as $index) {
            if (($index['name'] ?? null) === $name) {
                if (($index['columns'] ?? []) !== $columns || (bool) ($index['unique'] ?? false) !== $unique) {
                    throw new RuntimeException("رفض rollback: الفهرس {$name} تغير عن التعريف الذي أنشأته الهجرة.");
                }
                Schema::table(self::TABLE, fn (Blueprint $table) => $unique ? $table->dropUnique($name) : $table->dropIndex($name));
                return;
            }
        }
    }
};
