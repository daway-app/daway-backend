<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class PatientInquiry extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $table = 'patient_inquiries';

    public const STATUSES = ['new', 'answered', 'closed'];

    public const AVAILABILITY_STATUSES = ['available', 'unavailable', 'low_stock'];

    protected $fillable = [
        'user_id',
        'pharmacy_id',
        'medicine_id',
        'message',
        'status',
        'reply',
        'availability_status',
        'replied_at',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'reply', 'availability_status'])
            ->logOnlyDirty();
    }

    protected function casts(): array
    {
        return [
            'replied_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class);
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(PatientInquiryMessage::class, 'patient_inquiry_id')->oldest('created_at');
    }

    public function lastMessage(): ?PatientInquiryMessage
    {
        return $this->hasOne(PatientInquiryMessage::class, 'patient_inquiry_id')->latest('created_at')->first();
    }

    public function unreadMessagesCount(): int
    {
        return $this->messages()->whereNull('read_at')->count();
    }
}
