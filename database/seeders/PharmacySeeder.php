<?php

namespace Database\Seeders;

use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;

class PharmacySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create a user for the pharmacy
        $pharmacyUser = User::create([
            'name' => 'Pharmacy User',
            'email' => 'pharmacy@daway.com',
            'password' => Hash::make('password'),
            'role' => 'pharmacy',
            'phone' => '+970591234567',
        ]);

        // Create a pharmacy linked to the pharmacy user
        Pharmacy::create([
            'user_id' => $pharmacyUser->id,
            'pharmacy_custom_id' => 'PH-' . Str::random(4),
            'pharmacy_name' => 'صيدلية الأمل',
            'address' => 'غزة، شارع الوحدة',
            'latitude' => 31.501600,
            'longitude' => 34.466800,
            'phone_number' => '+970591234567',
            'is_active' => true,
        ]);

        // You can add more pharmacies here
        // Pharmacy::create([
        //     'user_id' => User::factory()->create(['role' => 'pharmacy'])->id,
        //     'pharmacy_custom_id' => 'PH-' . Str::random(4),
        //     'pharmacy_name' => 'صيدلية الشفاء',
        //     'address' => 'نابلس، شارع حطين',
        //     'latitude' => 32.2238,
        //     'longitude' => 35.2627,
        //     'phone_number' => '+970598765432',
        //     'is_active' => true,
        // ]);
    }
}
