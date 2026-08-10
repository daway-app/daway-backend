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
                'email' => 'admin@daway.com',
                'password' => Hash::make('Admin@12345'),
            ]
        );
    }
}
