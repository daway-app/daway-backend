<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * جلسة مسح بالهاتف — كائن مؤقت يربط تطبيق الجوال بشاشة نقاط البيع.
 *
 * ── دورة الحياة ─────────────────────────────────────────────────────
 *   waiting  → أُنشئت على الويب، ويُعرض الـQR. لا جهاز مقترن بعد.
 *   paired   → جهاز واحد مقترن، ويحقّ له إرسال الباركود.
 *   expired  → انتهى `expires_at` (يُوسَم كسولًا عند أول قراءة).
 *   closed   → أُغلقت صريحًا (`destroy`).
 *
 * ── ما لا يُخرج أبدًا ────────────────────────────────────────────────
 * `pairing_token` مخفي على مستوى الموديل (`$hidden`) كدفاع في العمق —
 * حتى لو أُعيد الموديل خامًا سهوًا في استجابة، لا يتسرّب المُهشَّر.
 *
 * ⚠️ هذا الموديل **لا يمسّ** السلة أو الطلبات أو المخزون. قراءته الوحيدة
 * خارج نطاقه هي `pharmacies`/`users` للعزل والتدقيق.
 */
class ScanSession extends Model
{
    public const STATUS_WAITING = 'waiting';

    public const STATUS_PAIRED = 'paired';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CLOSED = 'closed';

    /** لا تسلسل: المفتاح uuid يُولَّد في `creating`. */
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'pharmacy_id',
        'created_by',
        'pairing_code',
        'pairing_token',
        'device_token',
        'status',
        'active_device_id',
        'active_device_name',
        'pending_device_id',
        'pending_device_name',
        'scanned_barcode',
        'expires_at',
        'closed_at',
    ];

    /** 🔴 السرّان المؤقتان لا يُخرجان في أي استجابة. */
    protected $hidden = [
        'pairing_token',
        'device_token',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $session) {
            if (empty($session->id)) {
                $session->id = (string) Str::uuid();
            }
        });
    }

    /* ─── العلاقات ────────────────────────────────────────────────────── */

    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ─── النطاقات ────────────────────────────────────────────────────── */

    /** العزل الأساسي — يُستدعى في كل استعلام بلا استثناء. */
    public function scopeForPharmacy(Builder $query, int $pharmacyId): Builder
    {
        return $query->where('pharmacy_id', $pharmacyId);
    }

    /** الجلسات القابلة للاستخدام الآن (لم تُغلق ولم تنتهِ). */
    public function scopeLive(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [self::STATUS_WAITING, self::STATUS_PAIRED])
            ->where('expires_at', '>', now());
    }

    /* ─── الحالة ──────────────────────────────────────────────────────── */

    /**
     * منتهية أو مغلقة؟
     *
     * يُفحص زمنيًّا **بالإضافة** إلى الحالة: جلسة `waiting` تجاوزت
     * `expires_at` تبقى `waiting` في العمود حتى تُقرأ — فلا يجوز الاعتماد
     * على العمود وحده وإلا مُنح جهاز مهلة أبدية.
     */
    public function isExpired(): bool
    {
        if (in_array($this->status, [self::STATUS_EXPIRED, self::STATUS_CLOSED], true)) {
            return true;
        }

        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * الثواني المتبقية — تُحسب على الخادم لأن ساعة الجهاز غير موثوقة.
     *
     * الحساب بـ`getTimestamp()` لا `diffInSeconds()` عمدًا: توقيع
     * `diffInSeconds($x, false)` تغيّر بين إصدارات Carbon، والمقارنة
     * الرقمية الصريحة لا تحتمل التأويل.
     */
    public function secondsRemaining(): int
    {
        if ($this->expires_at === null) {
            return 0;
        }

        return max(0, $this->expires_at->getTimestamp() - now()->getTimestamp());
    }

    /** مقترنة بجهاز فعليًّا؟ (شرط إرسال الباركود.) */
    public function isPaired(): bool
    {
        return $this->status === self::STATUS_PAIRED && $this->active_device_id !== null;
    }

    /* ─── الانتقالات ──────────────────────────────────────────────────── */

    /**
     * وسم الجلسة منتهية (انتهاء كسول).
     *
     * لا نكتب إن كانت منتهية أصلًا: كل استطلاع بعد الانتهاء كان سيُنفّذ
     * `UPDATE` بلا معنى — والاستطلاع كل ثانيتين، فالفارق ليس نظريًّا.
     */
    public function markExpired(): bool
    {
        if ($this->status === self::STATUS_EXPIRED) {
            return false;
        }

        $this->forceFill(['status' => self::STATUS_EXPIRED])->save();

        return true;
    }

    /**
     * إغلاق الجلسة صريحًا — يُبطل التوكن فورًا.
     *
     * الإغلاق المزدوج لا يُعاد كتابته (يبقى `closed_at` الأول كما هو).
     */
    public function markClosed(): bool
    {
        if (in_array($this->status, [self::STATUS_CLOSED, self::STATUS_EXPIRED], true)) {
            return false;
        }

        $this->forceFill([
            'status' => self::STATUS_CLOSED,
            'closed_at' => now(),
        ])->save();

        return true;
    }

    /** @return list<string> */
    public static function statuses(): array
    {
        return [
            self::STATUS_WAITING,
            self::STATUS_PAIRED,
            self::STATUS_EXPIRED,
            self::STATUS_CLOSED,
        ];
    }
}
