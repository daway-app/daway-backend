<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create an Admin User
        User::create([
            'name' => 'Admin User',
            'email' => 'admin@daway.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        // Create some Patient Users using the factory
        User::factory(10)->create([
            'role' => 'patient',
        ]);

        // Call other seeders
        $this->call([
            MedicineSeeder::class,
            PharmacySeeder::class,
        ]);
    }
}
