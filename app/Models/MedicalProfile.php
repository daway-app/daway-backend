<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class MedicalProfile extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $table = 'medical_profiles';

    protected $fillable = [
        'user_id',            // صاحب الملف الطبي (unique — مستخدم واحد = ملف واحد بس)
        'allergies',           // الحساسيات
        'chronic_diseases',    // الأمراض المزمنة
        'blood_type',          // فصيلة الدم
        'notes',               // ملاحظات إضافية
    ];

    /**
     * علاقة عكسية: هاد الملف الطبي تبع مستخدم وحيد بالضبط.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
