<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@daway.com'],
            [
                'name' => 'Admin',
                'password' => Hash::make('Admin@12345'),
                'role' => 'admin',
                'phone' => '+970599999999',
                'phone_verified_at' => now(),
                'email_verified_at' => now(),
                'is_active' => true,
            ]
        );
    }
}
