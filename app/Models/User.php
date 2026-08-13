<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'role',
        'is_active',
        'address',
        'birth_date',
        'avatar',
        'emergency_contact',
        'pharmacy_id', //
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

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


    public function pharmacyByCustomId(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class, 'pharmacy_id', 'pharmacy_custom_id');
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
    public function availabilityNotifications(): HasMany
{
    return $this->hasMany(AvailabilityNotification::class);
}
}
