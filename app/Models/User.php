<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, LogsActivity, Notifiable;

    /**
     * قائمة بيضاء لـ mass-assignment — C1.
     * الحقول الحساسة (role, is_active, must_change_password, email_verified_at,
     * phone_verified_at) غير مشمولة عمداً لمنع التصعيد عبر الـ payload.
     * اضبطها صراحة عبر methods مخصصة في الـ controller.
     *
     * C6: pharmacy_id (string) أزيل — العمود ميت ويُحذف في migration لاحقة.
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'address',
        'birth_date',
        'notifications_enabled',
        'avatar',
        'latitude',
        'longitude',
        'emergency_contact',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'role', 'is_active', 'phone']);
    }

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'notifications_enabled' => 'boolean',
            'birth_date' => 'date',
        ];
    }

    /**
     * H-13: إنشاء توكن مع تتبّع بداية سلسلة الـ refresh.
     * عمود chain_started_at يورَّث عبر الـ rotations حتى يبقى الحد المطلق
     * (MAX_TOKEN_AGE_DAYS في AuthController) مفعّلاً رغم تجديد التوكن.
     */
    public function createToken(string $name, array $abilities = ['*'])
    {
        $current = $this->currentAccessToken();

        $chainStartedAt = ($current && $current->chain_started_at)
            ? $current->chain_started_at
            : now();

        // forceCreate: chain_started_at ليس بـ $fillable الخاص بـ Sanctum (الـ create يُسقطه بصمت)
        $token = $this->tokens()->forceCreate([
            'name' => $name,
            'token' => hash('sha256', $plain = \Illuminate\Support\Str::random(40)),
            'abilities' => $abilities,
            'chain_started_at' => $chainStartedAt,
        ]);

        return new \Laravel\Sanctum\NewAccessToken($token, $token->getKey().'|'.$plain);
    }

    /**
     * Get the notifications for the user.
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function pharmacy(): HasOne
    {
        return $this->hasOne(Pharmacy::class);
    }

    public function medicalProfile(): HasOne
    {
        return $this->hasOne(MedicalProfile::class);
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class);
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    public function availabilityNotifications(): HasMany
    {
        return $this->hasMany(AvailabilityNotification::class);
    }

    public function patientInquiries(): HasMany
    {
        return $this->hasMany(PatientInquiry::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }
}
