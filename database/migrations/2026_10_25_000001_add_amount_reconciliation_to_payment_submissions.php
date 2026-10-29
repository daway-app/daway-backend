<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * مطابقة مبلغ التحويل مع إجمالي الطلب — مع **إعادة بناء صادقة** للصفوف القديمة.
 *
 * ── المشكلة في النسخة السابقة ──────────────────────────────────────
 * كانت تضيف `amount_status` بـ`default('matches')`. النتيجة على قاعدة قائمة:
 * **كل** إرسال دفع قديم يصبح «مطابق» بمجرد إضافة العمود — وهو ادّعاء لم
 * يُثبت أبدًا (الصفوف كُتبت قبل وجود المقارنة أصلًا). أي تقرير أو مراجعة
 * إدارية كانت ستبني على قيمة كاذبة.
 *
 * ── السلوك الحالي ─────────────────────────────────────────────────
 *  · `amount_status` **nullable بلا default** ⇒ لا قيمة كاذبة لأي صف لم يُقيَّم.
 *  · بعد إضافة الأعمدة تُعاد بناء القيم للصفوف القديمة من بيانات حقيقية:
 *    `order_grand_total = orders.total + orders.delivery_fee` (نفس معادلة
 *    `PaymentFlowService::grandTotal`)، ثم تُقارَن `amount` بها بنفس دقة
 *    المقارنة الإنتاجية (نصف سنت) ⇒ matches / under / over + overage.
 *  · الصف الذي لا يوجد له طلب (أو طلب بلا إجمالي) يبقى **NULL** = «غير معروف»
 *    — لا نخترع قيمة.
 *
 * ⚠️ القيمة المُعاد بناؤها تعتمد على **إجمالي الطلب الحالي**، لا على قيمته
 * لحظة الإرسال (غير محفوظة تاريخيًا). لذلك هي أفضل تقدير متاح، وليست شهادة:
 * قرار الاعتماد في `PaymentFlowService` يظل يقارن مباشرةً وقت المراجعة.
 */
return new class extends Migration
{
    private const TABLE = 'order_payment_submissions';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            throw new RuntimeException('جدول order_payment_submissions غير موجود؛ لم تُسجّل هجرة المطابقة كمكتملة.');
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            if (! Schema::hasColumn(self::TABLE, 'order_grand_total')) {
                $table->decimal('order_grand_total', 10, 2)->nullable()->after('amount');
            }

            if (! Schema::hasColumn(self::TABLE, 'amount_status')) {
                // 🔴 لا default: الصف الذي لم يُقيَّم يبقى NULL (غير معروف).
                $table->string('amount_status')->nullable()->after('order_grand_total');
            }

            if (! Schema::hasColumn(self::TABLE, 'overage_amount')) {
                $table->decimal('overage_amount', 10, 2)->default(0.00)->after('amount_status');
            }
        });

        // A database may contain columns from the earlier staged migration,
        // whose amount_status was NOT NULL DEFAULT 'matches'. Convert that
        // definition before backfill; never infer a match from the default.
        $amountStatus = $this->column('amount_status');
        if ($amountStatus !== null && (! ($amountStatus['nullable'] ?? false)
            || ($amountStatus['default'] ?? null) !== null)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->string('amount_status')->nullable()->default(null)->change();
            });
        }

        $this->assertColumnDefinition('order_grand_total', 'decimal', true);
        $this->assertColumnDefinition('amount_status', 'varchar', true);
        $this->assertColumnDefinition('overage_amount', 'decimal', false);
        $overage = $this->column('overage_amount');
        if (DB::connection()->getDriverName() !== 'sqlite'
            && (! is_numeric($overage['default'] ?? null) || (float) $overage['default'] !== 0.0)) {
            throw new RuntimeException('order_payment_submissions.overage_amount يجب أن يحتفظ بالقيمة الافتراضية صفر.');
        }

        $this->rebuildLegacyRows();
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        foreach (['amount_status', 'overage_amount', 'order_grand_total'] as $column) {
            if (Schema::hasColumn(self::TABLE, $column)) {
                Schema::table(self::TABLE, function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }

    /**
     * إعادة بناء القيم للصفوف التي كانت موجودة قبل الهجرة.
     *
     * تُعالَج بالدفعات (chunkById) لأن الجدول قد يكون كبيرًا، ولأن UPDATE
     * واحد ضخم قد يقفل الجدول. الشرط `order_grand_total IS NULL` يجعل
     * التشغيل المتكرر لا يلمس صفًا سبق تقييمه.
     */
    private function rebuildLegacyRows(): void
    {
        if (! Schema::hasTable('orders')) {
            DB::table(self::TABLE)->whereNull('order_grand_total')->where('amount_status', 'matches')
                ->update(['amount_status' => null]);
            return;
        }

        // Old default='matches' may have fabricated a status for rows without
        // a resolvable order. Clear only that unsupported assertion.
        DB::table(self::TABLE)->whereNull('order_grand_total')->where('amount_status', 'matches')
            ->where(function ($query): void {
                $query->whereNull('order_id')->orWhereNotExists(function ($orders): void {
                    $orders->selectRaw('1')->from('orders')->whereColumn('orders.id', self::TABLE.'.order_id');
                });
            })->update(['amount_status' => null]);

        DB::table(self::TABLE)
            ->whereNull('order_grand_total')
            ->whereNotNull('order_id')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $order = DB::table('orders')
                        ->where('id', $row->order_id)
                        ->first(['total', 'delivery_fee']);

                    if ($order === null) {
                        // طلب محذوف — لا نعرف الإجمالي. يبقى NULL بصدق.
                        continue;
                    }

                    $expected = round((float) $order->total + (float) ($order->delivery_fee ?? 0), 2);
                    $declared = round((float) $row->amount, 2);

                    // نفس دقة المقارنة في PaymentFlowService::compareAmount
                    if (abs($declared - $expected) < 0.005) {
                        $status = 'matches';
                    } else {
                        $status = $declared < $expected ? 'under' : 'over';
                    }

                    DB::table(self::TABLE)
                        ->where('id', $row->id)
                        ->update([
                            'order_grand_total' => $expected,
                            'amount_status' => $status,
                            'overage_amount' => $declared > $expected
                                ? round($declared - $expected, 2)
                                : 0,
                        ]);
                }
            });
    }

    private function column(string $name): ?array
    {
        foreach (Schema::getColumns(self::TABLE) as $column) {
            if (($column['name'] ?? null) === $name) {
                return $column;
            }
        }
        return null;
    }

    private function assertColumnDefinition(string $name, string $type, bool $nullable): void
    {
        $column = $this->column($name);
        $expectedType = DB::connection()->getDriverName() === 'sqlite' && $type === 'decimal' ? 'numeric' : $type;
        if ($column === null || strtolower((string) ($column['type_name'] ?? '')) !== $expectedType
            || (bool) ($column['nullable'] ?? false) !== $nullable) {
            throw new RuntimeException("تعريف order_payment_submissions.{$name} غير متوقع بعد الهجرة.");
        }
    }
};
