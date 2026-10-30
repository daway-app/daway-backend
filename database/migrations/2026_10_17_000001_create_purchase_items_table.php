<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * أسطر فاتورة الشراء.
 *
 * كانت `purchases` جدولًا بلا أسطر (إجماليات فقط) — لذلك لم تكن دورة
 * المشتريات قابلة للتنفيذ: لا يمكن معرفة أي دواء دخل المخزون ولا بكم سعر.
 * هذا الجدول يُكمل الدورة، ويُبقي `purchases` كما هو (لا تغيير على أعمدة
 * قائمة ⇒ لا مخاطرة على البيانات الحالية).
 *
 * `pharmacy_medicine_id` يبقى nullable: قد تُسجَّل فاتورة شراء لصنف غير
 * موجود بعد في المخزون، ويُنشأ السطر لاحقًا. لكن عند وجوده هنا **يُزاد
 * مخزون ذلك السطر بالذات** (لا بحث بالاسم).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('purchase_items')) {
            $this->assertExpectedSchema();

            return;
        }

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained('purchases')->cascadeOnDelete();

            $table->foreignId('pharmacy_medicine_id')->nullable()
                ->constrained('pharmacy_medicines')->nullOnDelete();
            $table->foreignId('medicine_id')->nullable()
                ->constrained('medicines')->nullOnDelete();
            $table->foreignId('moh_medicine_id')->nullable()
                ->constrained('moh_medicines')->nullOnDelete();

            $table->string('medicine_name', 200);
            $table->string('barcode', 32)->nullable();

            // التكلفة (لا سعر البيع) — فرق جوهري عن sale_items.unit_price.
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->integer('quantity')->default(1);
            $table->decimal('line_discount', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2)->default(0);

            $table->timestamps();
        });

        $this->assertExpectedSchema();
    }

    public function down(): void
    {
        if (Schema::hasTable('purchase_items')) {
            throw new RuntimeException(
                'رفض rollback لهجرة purchase_items لأنه سيحذف جدولًا قد يحتوي بيانات مشتريات. '
                .'يجب تصدير البيانات ومراجعة أثر الحذف يدويًا أولًا.'
            );
        }
    }

    private function assertExpectedSchema(): void
    {
        $expected = [
            'id', 'purchase_id', 'pharmacy_medicine_id', 'medicine_id', 'moh_medicine_id',
            'medicine_name', 'barcode', 'unit_cost', 'quantity', 'line_discount', 'line_total',
            'created_at', 'updated_at',
        ];
        $columns = Schema::getColumns('purchase_items');
        $actual = array_column($columns, 'name');

        if (array_diff($expected, $actual) || array_diff($actual, $expected)) {
            throw new RuntimeException('مخطط purchase_items جزئي أو غير متوقع؛ لم تُعدّل بيانات الجدول.');
        }

        $types = [
            'id' => 'bigint', 'purchase_id' => 'bigint', 'pharmacy_medicine_id' => 'bigint',
            'medicine_id' => 'bigint', 'moh_medicine_id' => 'bigint', 'medicine_name' => 'varchar',
            'barcode' => 'varchar', 'unit_cost' => 'decimal', 'quantity' => 'int',
            'line_discount' => 'decimal', 'line_total' => 'decimal', 'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
        ];
        $nullable = [
            'id' => false, 'purchase_id' => false, 'pharmacy_medicine_id' => true,
            'medicine_id' => true, 'moh_medicine_id' => true, 'medicine_name' => false,
            'barcode' => true, 'unit_cost' => false, 'quantity' => false,
            'line_discount' => false, 'line_total' => false, 'created_at' => true,
            'updated_at' => true,
        ];
        $lengths = ['medicine_name' => 200, 'barcode' => 32];
        $sqliteTypes = ['bigint' => 'integer', 'int' => 'integer', 'decimal' => 'numeric', 'timestamp' => 'datetime'];
        $driver = DB::connection()->getDriverName();
        foreach ($columns as $column) {
            $name = $column['name'] ?? '';
            $expectedType = $types[$name] ?? null;
            if ($driver === 'sqlite' && $expectedType !== null) {
                $expectedType = $sqliteTypes[$expectedType] ?? $expectedType;
            }
            if ($expectedType !== null && strtolower((string) ($column['type_name'] ?? '')) !== $expectedType) {
                throw new RuntimeException("نوع purchase_items.{$name} غير متوقع؛ لم تُعدّل البيانات.");
            }
            if (array_key_exists($name, $nullable) && (bool) ($column['nullable'] ?? false) !== $nullable[$name]) {
                throw new RuntimeException("قابلية NULL في purchase_items.{$name} غير متوقعة؛ لم تُعدّل البيانات.");
            }
            // Laravel's MySQL schema metadata exposes the length in `type`
            // (e.g. varchar(200)); it does not provide a separate `length` key.
            if ($driver !== 'sqlite' && isset($lengths[$name])
                && strtolower((string) ($column['type'] ?? '')) !== 'varchar('.$lengths[$name].')') {
                throw new RuntimeException("طول purchase_items.{$name} غير متوقع؛ لم تُعدّل البيانات.");
            }
        }

        $targets = [
            'purchase_id' => ['purchases', 'cascade'],
            'pharmacy_medicine_id' => ['pharmacy_medicines', 'set null'],
            'medicine_id' => ['medicines', 'set null'],
            'moh_medicine_id' => ['moh_medicines', 'set null'],
        ];

        foreach ($targets as $column => [$table, $delete]) {
            $matches = array_filter(Schema::getForeignKeys('purchase_items'), fn (array $key): bool =>
                ($key['columns'] ?? []) === [$column]
                && ($key['foreign_table'] ?? null) === $table
                && ($key['foreign_columns'] ?? []) === ['id']
                && strtolower((string) ($key['on_delete'] ?? '')) === $delete
            );

            if (! $matches) {
                throw new RuntimeException("مخطط purchase_items غير مكتمل: FK غير صحيح أو مفقود على {$column}.");
            }
        }
    }
};
