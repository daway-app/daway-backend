<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {

        User::updateOrCreate(
            ['email' => 'admin@daway.com'],
            [
                'name' => 'Admin User',
                'password' => Hash::make('password'),
                'role' => 'admin',
                'phone' => '+970591234567',
                'phone_verified_at' => now(),
                'email_verified_at' => now(),
                'is_active' => true,
            ]
        );


        User::factory(10)->create([
            'role' => 'patient',
            'phone_verified_at' => now(),
            'email_verified_at' => now(),
        ]);


        $this->call([
            AdminSeeder::class,
            MedicineSeeder::class,
            PharmacySeeder::class,
        ]);
    }
}
