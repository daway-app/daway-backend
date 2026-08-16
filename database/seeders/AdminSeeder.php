<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::updateOrCreate(
            ['email' => 'admin@daway.com'],
            [
                'name' => 'Admin',
                'password' => Hash::make('Admin@12345'),
                'phone' => '+970599999999',
                'phone_verified_at' => now(),
                'email_verified_at' => now(),
            ]
        );

        $admin->is_active = true;
        $admin->save();
        $admin->syncRoles(['admin']);
    }
}
