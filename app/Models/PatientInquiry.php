<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class PatientInquiry extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $table = 'patient_inquiries';

    public const STATUSES = ['new', 'answered', 'closed'];

    protected $fillable = [
        'user_id',
        'pharmacy_id',
        'medicine_id',
        'message',
        'status',
    ];

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
}
