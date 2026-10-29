<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * صيانة كتالوج وزارة الصحة عبر الـAPI — العمليتان القابلتان للتقسيم فقط.
 *
 * ── لماذا هاتان فقط؟ ────────────────────────────────────────────────
 * `moh:import` **مستبعَد عمدًا**: لا يدعم التقسيم (`--file` فقط)، ويعمل في
 * معاملة واحدة على 17,295 صفًا مع `memory_limit 512M` ⇒ استدعاء HTTP متزامن
 * يتجاوز قطع Render (~100ث) ويخاطر بـOOM. وهو bootstrap لمرة واحدة أصلًا.
 * `moh:sync` مستبعَد (شبكي، مهلة 180ث، مجدول). و`--fresh` مدمِّر ⇒ غير مكشوف.
 *
 * ── لماذا التقسيم لا الـjob؟ ────────────────────────────────────────
 * لا يوجد عامل طابور في الإنتاج: `Dockerfile` CMD يشغّل `php artisan serve`
 * فقط، و`QUEUE_CONNECTION` غير مضبوط (الافتراضي `database`) ⇒ أي job مدفوع
 * يبقى في جدول `jobs` للأبد. لذلك النمط هنا هو نفسه المستخدم في واجهة Blade:
 * طلب متزامن يعالج شريحة، والعميل يعيد النداء حتى `done`.
 *
 * ── حدود ضمان منع التكرار (اقرأها قبل الاعتماد عليها) ──────────────
 * القفل يستخدم `Cache::lock` على مخزن الكاش المضبوط. في الإنتاج
 * `CACHE_STORE=file` (render.yaml) — و`FileStore` يطبّق `LockProvider` عبر
 * `flock`، وهو **موثوق داخل حاوية واحدة فقط**. Render المجاني = نسخة واحدة،
 * فهذا كافٍ اليوم. ⚠️ **ليس قفلًا موزّعًا:** عند الترقية لأكثر من نسخة لن
 * يشترك الكاش الملفي بينها ⇒ يصبح منع التكرار عبر النسخ غير مضمون.
 * (نفس القيد الموثَّق في render.yaml لـ SESSION_DRIVER=file.)
 */
class CatalogMaintenanceController extends Controller
{
    /**
     * قائمة بيضاء صارمة: المعرّف العام ⇒ الأمر الفعلي وخياراته الثابتة.
     * سلسلة الطلب **لا تصل إلى Artisan إطلاقًا** — لا حقن أوامر ولا خيارات حرّة.
     */
    private const OPERATIONS = [
        'sync_categories' => [
            'command' => 'moh:sync-categories',
            'options' => [],
        ],
        // نفس ما كانت تشغّله واجهة Blade: `--subcategories-only` فقط
        // (STEP C) — لا نوسّع النطاق إلى التصنيف الكامل في هذه المرحلة.
        'classify_subcategories' => [
            'command' => 'classify:moh-catalog',
            'options' => ['--subcategories-only' => true],
        ],
    ];

    private const MAX_LIMIT = 1500;

    private const STATE_TTL_HOURS = 6;

    private const LOCK_SECONDS = 300;

    /**
     * هوية قفل تنفيذ **واحدة** لكل عمليات الصيانة المسموح بها — لا لكل عملية.
     *
     * السبب: العمليتان تكتبان نفس الجداول (`category_medicine_links`) وتُبطلان
     * نفس كاش الكتالوج، فتشغيلهما بالتوازي يُدخل تداخلًا على بيانات مترابطة.
     * لذلك `sync_categories` و`classify_subcategories` يتقاسمان قفلًا واحدًا:
     * ما دام أحدهما يعمل، يُرفض الآخر.
     */
    private const EXECUTION_LOCK_FILE = 'catalog-maintenance.lock';

    private const EXECUTION_LOCK_KEY = 'catalog-maintenance-lock';

    /** POST /api/admin/maintenance/catalog/run */
    public function run(Request $request): JsonResponse
    {
        $data = $request->validate([
            'operation' => ['required', 'string', Rule::in(array_keys(self::OPERATIONS))],
            'run_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'offset' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ]);

        $operation = (string) $data['operation'];
        $definition = self::OPERATIONS[$operation];
        $limit = (int) ($data['limit'] ?? self::MAX_LIMIT);
        $providedRunId = $data['run_id'] ?? null;

        $currentKey = "catalog-op-current:{$operation}";
        $currentRunId = Cache::get($currentKey);

        // ── هوية الجولة ────────────────────────────────────────────────
        if ($providedRunId === null) {
            if ($currentRunId !== null) {
                return $this->conflict(
                    'توجد جولة جارية لهذه العملية — أرسل run_id لمتابعتها.',
                    ['run_id' => $currentRunId]
                );
            }

            $runId = (string) Str::uuid();
        } else {
            if ($currentRunId !== $providedRunId) {
                return $this->conflict('جولة غير معروفة أو منتهية — ابدأ جولة جديدة بلا run_id.');
            }

            $runId = (string) $providedRunId;
        }

        $stateKey = $this->stateKey($operation, $runId);
        $state = Cache::get($stateKey);

        // جولة مُعلَنة لكن حالتها انقضت (TTL 6 ساعات) ⇒ لا نستأنف من الصفر بصمت
        if ($providedRunId !== null && ! is_array($state)) {
            Cache::forget($currentKey);

            return $this->conflict('انقضت حالة الجولة — ابدأ جولة جديدة بلا run_id.');
        }

        $storedOffset = is_array($state) ? (int) ($state['offset'] ?? 0) : 0;

        // الاستئناف لا يقبل offset متأخرًا عن المثبَّت (حماية من عميل قديم/مكرَّر)
        if (isset($data['offset']) && (int) $data['offset'] !== $storedOffset) {
            return $this->conflict(
                'offset غير مطابق لحالة الجولة.',
                ['offset' => $storedOffset]
            );
        }

        $offset = $storedOffset;

        // ── قفل التنفيذ ────────────────────────────────────────────────
        // قفلان متكاملان، **بهوية مشتركة بين العمليتين** (لا لكل عملية):
        //   1) `flock` محتجز **طوال الشريحة** — لا تنتهي صلاحيته أثناء التنفيذ،
        //      ويُحرّره نظام التشغيل إن ماتت العملية (لا حجب عالق).
        //   2) `Cache::lock` كحاجز إضافي محمول — لكنه **مُدخل كاش بـTTL** لا
        //      flock محتجز (`FileStore::add` يكتب طابع انتهاء)، فقد ينتهي أثناء
        //      شريحة طويلة. لذلك لا يُعتمد عليه وحده.
        // ⚠️ لا شيء منهما قفل موزّع: كلاهما داخل الحاوية الواحدة (Render free).
        //
        // الحجز **داخل** الـtry عمدًا: أي استثناء (بما فيه من `flock` نفسها)
        // يمرّ بـ`finally` فيُغلق المقبض — لا نافذة تسريب.
        $executionLock = null;
        $cacheLock = null;
        $commandStarted = false;

        $startedAt = microtime(true);

        try {
            $executionLock = $this->acquireExecutionLock();

            if ($executionLock === false) {
                return $this->conflict('عملية صيانة أخرى قيد التنفيذ فعليًا — أعد المحاولة بعد قليل.');
            }

            if ($executionLock === null) {
                // تعذّر فتح ملف القفل أو حجزه — لا نبدأ الأمر إطلاقًا
                return response()->json([
                    'success' => false,
                    'message' => 'تعذّر حجز قفل التنفيذ — لم تبدأ العملية. راجع سجلّات الخادم.',
                ], 500);
            }

            $cacheLock = Cache::lock(self::EXECUTION_LOCK_KEY, self::LOCK_SECONDS);

            if (! $cacheLock->get()) {
                return $this->conflict('عملية صيانة أخرى قيد التنفيذ — أعد المحاولة بعد قليل.');
            }

            Cache::put($currentKey, $runId, now()->addHours(self::STATE_TTL_HOURS));

            Log::info('catalog maintenance chunk started', [
                'operation' => $operation,
                'command' => $definition['command'],
                'run_id' => $runId,
                'admin_id' => $request->user()?->id,
                'offset' => $offset,
                'limit' => $limit,
            ]);

            $commandStarted = true;

            $exitCode = Artisan::call($definition['command'], array_merge([
                '--offset' => $offset,
                '--limit' => $limit,
                '--state' => $stateKey,
            ], $definition['options']));

            $output = trim(Artisan::output());
        } catch (Throwable $e) {
            // لا نمسّ حالة جولة قائمة إن وقع الاستثناء **قبل** بدء الأمر:
            // قد تكون تقدّمًا صالحًا من شريحة سابقة لنفس الجولة.
            if ($commandStarted) {
                Cache::forget($currentKey);
                Cache::forget($stateKey);
            }

            Log::error('catalog maintenance chunk threw', [
                'operation' => $operation,
                'command' => $definition['command'],
                'run_id' => $runId,
                'admin_id' => $request->user()?->id,
                'offset' => $offset,
                'command_started' => $commandStarted,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $commandStarted
                    ? 'فشل تنفيذ الشريحة — أُلغيت الجولة، ابدأ من جديد.'
                    : 'فشل قبل بدء الأمر — لم تُلغَ أي جولة.',
            ], 500);
        } finally {
            if ($cacheLock !== null) {
                $cacheLock->release();
            }

            if (is_resource($executionLock)) {
                $this->releaseExecutionLock($executionLock);
            }
        }

        $state = Cache::get($stateKey);
        $hasState = is_array($state) && $state !== [];

        // لا نختلق أرقامًا: عند غياب الحالة نُعيد ما نعرفه فقط (offset الحالي)
        $stateOffset = $hasState ? (int) ($state['offset'] ?? $offset) : $offset;
        $total = $hasState ? (int) ($state['total'] ?? 0) : 0;
        // `null` عند غياب المفتاح — لا صفرًا مختلقًا (الأمر لم يُبلّغ بعدّاد)
        $processed = ($hasState && isset($state['processed'])) ? (int) $state['processed'] : null;
        $done = $hasState && $stateOffset >= $total;

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($exitCode !== 0) {
            // الأمر فشل بنفسه (لا استثناء).
            //
            // حالتان مختلفتان تمامًا:
            //  · **لا تقدّم محفوظ** (فشل الشريحة الأولى): الجولة لم تبدأ فعليًا،
            //    فنُزيل علامتها — وإلا بقيت «جولة جارية» تمنع أي محاولة جديدة
            //    حتى انتهاء الـTTL، مع رسالة مضلِّلة.
            //  · **يوجد تقدّم محفوظ**: نحفظه كما هو — نفس الشريحة قابلة لإعادة
            //    المحاولة من نفس الـoffset بلا فقدان عمل.
            $persisted = Cache::get($stateKey);
            $hasProgress = is_array($persisted) && $persisted !== [];

            if (! $hasProgress) {
                Cache::forget($currentKey);
            }

            Log::error('catalog maintenance chunk failed', [
                'operation' => $operation,
                'command' => $definition['command'],
                'run_id' => $runId,
                'admin_id' => $request->user()?->id,
                'offset' => $offset,
                'exit_code' => $exitCode,
                'duration_ms' => $durationMs,
                'has_progress' => $hasProgress,
                'output' => $output,
            ]);

            return response()->json([
                'success' => false,
                'message' => $hasProgress
                    ? 'فشل تنفيذ الأمر — لم تتقدّم الجولة، أعد المحاولة بالـrun_id نفسه.'
                    : 'فشل تنفيذ الأمر قبل أي تقدّم — أُلغيت الجولة، ابدأ من جديد.',
                'data' => $this->progressPayload($runId, $operation, $definition, $stateOffset, $limit, $total, $processed, false, $exitCode, $state),
            ], 500);
        }

        if ($done) {
            Cache::forget($currentKey);
            Cache::forget($stateKey);
        }

        Log::info('catalog maintenance chunk finished', [
            'operation' => $operation,
            'command' => $definition['command'],
            'run_id' => $runId,
            'admin_id' => $request->user()?->id,
            'offset' => $stateOffset,
            'total' => $total,
            'done' => $done,
            'duration_ms' => $durationMs,
        ]);

        return response()->json([
            'success' => true,
            'message' => $done ? 'اكتملت العملية.' : 'تمت معالجة شريحة — تابع بالـrun_id نفسه.',
            'data' => $this->progressPayload($runId, $operation, $definition, $stateOffset, $limit, $total, $processed, $done, $exitCode, $state),
        ]);
    }

    /**
     * حالة تقدّم صادقة: كل رقم هنا مأخوذ من الحالة التي كتبها الأمر نفسه.
     * `counters` تُعاد كما هي بلا تحويل — لا نضيف أرقامًا مشتقّة كاذبة.
     *
     * @param  array<string,mixed>  $definition
     * @param  array<string,mixed>|null  $state
     * @return array<string,mixed>
     */
    private function progressPayload(
        string $runId,
        string $operation,
        array $definition,
        int $offset,
        int $limit,
        int $total,
        ?int $processed,
        bool $done,
        int $exitCode,
        ?array $state,
    ): array {
        return [
            'run_id' => $runId,
            'operation' => $operation,
            'command' => $definition['command'],
            'offset' => $offset,
            'limit' => $limit,
            'total' => $total,
            'processed' => $processed,
            'done' => $done,
            'exit_code' => $exitCode,
            'counters' => $state ?? [],
        ];
    }

    /**
     * قفل تنفيذ محتجز **طوال الشريحة** — `flock` على ملف محلي، **بهوية مشتركة**
     * بين كل عمليات الصيانة المسموح بها.
     *
     * ── لماذا فوق قفل الكاش؟ ─────────────────────────────────────────────
     * `Cache::lock` في مخزن `file` ليس flock محتجزًا: `FileStore::add()` يأخذ
     * flock **لحظة القراءة-الكتابة فقط** ثم يكتب طابع انتهاء — أي أنه **مُدخل
     * كاش بـTTL**. فشريحة تتجاوز الـTTL تُنهي القفل أثناء تنفيذها، فيمكن لطلب
     * ثانٍ أن يبدأ نفس الشريحة. هذا القفل يحلّ ذلك لأنه:
     *   · يبقى ما دامت العملية حيّة ⇒ **لا سباق TTL** أثناء التنفيذ.
     *   · يُحرّره نظام التشغيل إذا ماتت العملية ⇒ **لا حجب عالق** لـTTL كامل.
     *
     * ── لماذا بلا `@`؟ ───────────────────────────────────────────────────
     * لا نُخفي التحذيرات: نلتقطها صراحةً في `captureWarnings()` ونسجّلها،
     * ثم **نفحص النتيجة الفعلية** (`is_resource` / `=== true`) — فلا نُخفي فشلًا
     * حقيقيًا ولا نتعامل مع نجاح كأنه فشل.
     *
     * ⚠️ **ليس قفلًا موزّعًا:** `flock` محلي للحاوية الواحدة (Render free =
     * نسخة واحدة). عند التوسّع لأكثر من نسخة لن يمنع التنفيذ المتوازي بينها —
     * ذلك يحتاج مخزنًا مشتركًا (Redis/DB) وهو خارج نطاق هذه المرحلة.
     *
     * @return resource|false|null  مقبض عند النجاح · false إذا كان القفل غير متاح
     *                              (تنازع مع تشغيل آخر) · null عند تعذّر فتح الملف
     */
    private function acquireExecutionLock()
    {
        $directory = storage_path('framework/cache');

        if (! is_dir($directory)) {
            [$created, $warning] = $this->captureWarnings(
                fn () => mkdir($directory, 0o775, true)
            );

            if (! $created && ! is_dir($directory)) {
                Log::error('catalog maintenance: lock directory unavailable', [
                    'directory' => $directory,
                    'warning' => $warning,
                ]);

                return null;
            }
        }

        $path = $directory.DIRECTORY_SEPARATOR.self::EXECUTION_LOCK_FILE;

        [$handle, $openWarning] = $this->captureWarnings(fn () => fopen($path, 'c'));

        if (! is_resource($handle)) {
            Log::error('catalog maintenance: cannot open lock file', [
                'path' => $path,
                'warning' => $openWarning,
            ]);

            return null;
        }

        [$acquired, $lockWarning] = $this->captureWarnings(
            fn () => flock($handle, LOCK_EX | LOCK_NB)
        );

        if ($acquired !== true) {
            fclose($handle);

            // نُسجّل التحذير (لا نُخفيه) ثم نُبلّغ بأن القفل غير متاح — والنتيجة
            // الحقيقية هي ما نحكم به لا وجود التحذير (يختلف بين الأنظمة).
            if ($lockWarning !== null) {
                Log::warning('catalog maintenance: flock reported a warning', [
                    'path' => $path,
                    'warning' => $lockWarning,
                ]);
            }

            return false;
        }

        return $handle;
    }

    /**
     * ينفّذ `$callback` ويلتقط أول تحذير PHP وقع بلا إخفائه.
     *
     * @return array{0:mixed,1:?string}  [نتيجة الاستدعاء، رسالة التحذير أو null]
     */
    private function captureWarnings(callable $callback): array
    {
        $warning = null;

        set_error_handler(function (int $level, string $message) use (&$warning): bool {
            $warning ??= $message;

            return true; // نمنع Laravel من تحويله إلى ErrorException، لكننا نحتفظ به
        });

        try {
            // ترتيب التقييم: الاستدعاء أولًا ثم قراءة التحذير الذي سجّله
            return [$callback(), $warning];
        } finally {
            restore_error_handler();
        }
    }

    /** @param  resource  $handle */
    private function releaseExecutionLock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    private function stateKey(string $operation, string $runId): string
    {
        return "catalog-op-state:{$operation}:{$runId}";
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function conflict(string $message, array $data = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => $data,
        ], 409);
    }
}
