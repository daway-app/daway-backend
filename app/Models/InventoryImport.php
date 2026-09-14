<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * جلسة استيراد جماعي لمخزون صيدلية.
 *
 * دورة الحياة: uploaded → previewed → ready → committed
 *                                   ↘ expired / failed
 *
 * الحالة القابلة للتلاعب (نتائج المعاينة والقرارات) تُخزَّن في rows_payload
 * على الخادم، ولا يُقبل أي قرار من الواجهة إلا بإعادة التحقق منه.
 */
class InventoryImport extends Model
{
    use HasFactory;

    /*
    |--------------------------------------------------------------------------
    | دورة حياة الجلسة
    |--------------------------------------------------------------------------
    */

    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_PREVIEWED = 'previewed';

    public const STATUS_READY = 'ready';

    public const STATUS_COMMITTED = 'committed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_FAILED = 'failed';

    /** الحالات التي لم تُحسم بعد — قابلة للتعديل. */
    public const OPEN_STATUSES = [
        self::STATUS_UPLOADED,
        self::STATUS_PREVIEWED,
        self::STATUS_READY,
    ];

    /*
    |--------------------------------------------------------------------------
    | حالات الصف — تصنيف حتمي مبني على قواعد، وليس رقماً وهمياً
    |--------------------------------------------------------------------------
    */

    public const ROW_EXACT_EN = 'EXACT_EN';

    public const ROW_EXACT_AR = 'EXACT_AR_NORMALIZED';

    public const ROW_ALIAS = 'ALIAS_MATCH';

    public const ROW_MOH = 'MOH_MATCH';

    public const ROW_FUZZY = 'FUZZY_MATCH';

    /** مطابقة تقريبية أعطت أكثر من مرشّح — غامضة وتحتاج قراراً بشرياً. */
    public const ROW_REVIEW = 'REVIEW_REQUIRED';

    public const ROW_UNMATCHED = 'UNMATCHED';

    public const ROW_INVALID = 'INVALID_ROW';

    public const ROW_DUPLICATE = 'DUPLICATE';

    /** الحالات التي تعني "طابقنا دواءً موجوداً فعلاً" — قابلة للـ commit بلا مراجعة. */
    public const ROW_AUTO_MATCHED = [
        self::ROW_EXACT_EN,
        self::ROW_EXACT_AR,
        self::ROW_ALIAS,
    ];

    /** الحالات التي تحتاج قراراً صريحاً من الصيدلي. */
    public const ROW_NEEDS_DECISION = [
        self::ROW_MOH,
        self::ROW_FUZZY,
        self::ROW_REVIEW,
        self::ROW_UNMATCHED,
        self::ROW_DUPLICATE,
    ];

    /*
    |--------------------------------------------------------------------------
    | قرارات الصف
    |--------------------------------------------------------------------------
    */

    /** مرتبط بدواء موجود — سيُكتب في المخزون. */
    public const DECISION_LINK = 'link';

    /** إنشاء دواء جديد في الكتالوج — يتطلب تأكيداً صريحاً. */
    public const DECISION_CREATE = 'create';

    /** تجاهل الصف تماماً. */
    public const DECISION_SKIP = 'skip';

    /** لم يُحسم بعد — يمنع الـ commit. */
    public const DECISION_PENDING = 'pending';

    /*
    |--------------------------------------------------------------------------
    | قرارات الدمج للمكرّرات
    |--------------------------------------------------------------------------
    */

    /** آخر صف يفوز (الكمية المطلقة). */
    public const MERGE_KEEP_LAST = 'keep_last';

    /** أول صف يفوز. */
    public const MERGE_KEEP_FIRST = 'keep_first';

    /** تجاهل كل صفوف المجموعة. */
    public const MERGE_SKIP_ALL = 'skip_all';

    protected $table = 'inventory_imports';

    protected $fillable = [
        'uuid',
        'pharmacy_id',
        'user_id',
        'original_filename',
        'file_hash',
        'file_path',
        'status',
        'total_rows',
        'matched_rows',
        'review_rows',
        'unmatched_rows',
        'duplicate_rows',
        'error_rows',
        'committed_rows',
        'created_medicines',
        'rows_payload',
        'commit_summary',
        'committed_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'rows_payload' => 'array',
            'commit_summary' => 'array',
            'committed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $import): void {
            $import->uuid ??= (string) Str::uuid();

            if ($import->expires_at === null) {
                $import->expires_at = now()->addHours(
                    (int) config('inventory_import.session_ttl_hours', 24)
                );
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | العلاقات
    |--------------------------------------------------------------------------
    */

    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /*
    |--------------------------------------------------------------------------
    | مساعدات دورة الحياة
    |--------------------------------------------------------------------------
    */

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isCommitted(): bool
    {
        return $this->status === self::STATUS_COMMITTED;
    }

    /**
     * هل يمكن تنفيذ الـ commit الآن؟
     * نرفض: المُنفَّذ سابقاً، المنتهي، والفاشل.
     */
    public function canCommit(): bool
    {
        return in_array($this->status, [self::STATUS_PREVIEWED, self::STATUS_READY], true)
            && ! $this->isExpired();
    }

    /** صفوف المعاينة المخزّنة على الخادم. */
    public function rows(): array
    {
        return (array) ($this->rows_payload['rows'] ?? []);
    }

    /**
     * مجموعات التكرار: مفتاح المجموعة => [أرقام الصفوف].
     *
     * مفتاح المجموعة ليس معرّف دواء دائماً — قد يكون `m:{id}` لدواء محلي،
     * أو `moh:{id}` لعنصر وزاري، أو `in:{اسم مطبّع}` لصف لم يُطابق شيئاً.
     * لذلك يُعامل كمفتاح معتم فقط ولا يُفكَّك.
     */
    public function duplicateGroups(): array
    {
        return (array) ($this->rows_payload['groups'] ?? []);
    }

    /** قرارات الدمج المحفوظة: مفتاح المجموعة => keep_last|keep_first|skip_all. */
    public function mergeDecisions(): array
    {
        return (array) ($this->rows_payload['merges'] ?? []);
    }

    /**
     * المسار المطلق للملف على القرص.
     *
     * نستخدم Storage::path() ولا نبني المسار يدوياً: جذر قرص local تغيّر في
     * Laravel الحديث إلى storage/app/private، فبناء المسار يدوياً ينتج مساراً
     * خاطئاً. واسم الملف مولّد من الخادم دائماً — لا احتمال path traversal.
     */
    public function absoluteFilePath(): string
    {
        return Storage::disk((string) config('inventory_import.disk', 'local'))
            ->path($this->file_path);
    }
}
