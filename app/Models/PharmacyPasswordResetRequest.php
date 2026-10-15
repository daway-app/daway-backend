<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * طلب استعادة كلمة مرور صيدلية — أنشأه المالك من شاشة الدخول، ويراجعه الأدمن.
 *
 * 🔴 لا رمز ولا OTP هنا: الطلب مجرد إشعار بانتظار المراجعة. كلمة المرور
 * الجديدة لا تُولَّد إلا عند `approve` من الأدمن، وتُسلَّم مرة واحدة عبر
 * لوحة الأدمن (وهي نفس آلية `reset-credentials`: تُخزَّن مشفّرة في
 * `pharmacies.pending_password` و`delivered_at` يُصفَّر حتى تُسلَّم).
 */
class PharmacyPasswordResetRequest extends Model
{
    protected $fillable = [
        'pharmacy_id',
        'status',
        'reviewed_by',
        'admin_notes',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
