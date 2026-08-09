<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
    'name',
    'phone',
    'email',
    'password',
    'role',
    'pharmacy_id',
    'is_active',
    'phone_verified_at',
];

    protected $hidden = [
        'password', 'remember_token',
    ];
}
