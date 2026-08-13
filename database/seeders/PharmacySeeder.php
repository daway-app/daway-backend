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
    public function run(): void
    {
        // ✅ استخدام updateOrCreate عشان ما يكرر
        $pharmacyUser = User::updateOrCreate(
            ['email' => 'pharmacy@daway.com'],
            [
                'name' => 'Pharmacy User',
                'password' => Hash::make('password'),
                'role' => 'pharmacy',
                'phone' => '+970591234567',
                'phone_verified_at' => now(),
                'email_verified_at' => now(),
                'is_active' => true,
            ]
        );

        // ✅ استخدام updateOrCreate للصيدلية
        Pharmacy::updateOrCreate(
            ['pharmacy_custom_id' => 'PH-1234'],
            [
                'user_id' => $pharmacyUser->id,
                'pharmacy_custom_id' => 'PH-1234',
                'pharmacy_name' => 'صيدلية الأمل',
                'address' => 'غزة، شارع الوحدة',
                'latitude' => 31.501600,
                'longitude' => 34.466800,
                'phone_number' => '+970591234567',
                'is_active' => true,
                'avg_rating' => 0.00,
            ]
        );

        // ✅ صيدلية ثانية
        $pharmacyUser2 = User::updateOrCreate(
            ['email' => 'pharmacy2@daway.com'],
            [
                'name' => 'Pharmacy User 2',
                'password' => Hash::make('password'),
                'role' => 'pharmacy',
                'phone' => '+970598765432',
                'phone_verified_at' => now(),
                'email_verified_at' => now(),
                'is_active' => true,
            ]
        );

        Pharmacy::updateOrCreate(
            ['pharmacy_custom_id' => 'PH-5678'],
            [
                'user_id' => $pharmacyUser2->id,
                'pharmacy_custom_id' => 'PH-5678',
                'pharmacy_name' => 'صيدلية الشفاء',
                'address' => 'نابلس، شارع حطين',
                'latitude' => 32.223800,
                'longitude' => 35.262700,
                'phone_number' => '+970598765432',
                'is_active' => true,
                'avg_rating' => 0.00,
            ]
        );
    }
}
