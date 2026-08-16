<?php

namespace Database\Seeders;

use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PharmacySeeder extends Seeder
{
    public function run(): void
    {
        // ✅ استخدام updateOrCreate عشان ما يكرر
        $pharmacyUser = User::updateOrCreate(
            ['email' => 'pharmacy@daway.com'],
            [
                'name' => 'صيدلية الأمل',
                'password' => Hash::make('password'),
                'phone' => '+970591234567',
                'phone_verified_at' => now(),
                'email_verified_at' => now(),
            ]
        );

        $pharmacyUser->is_active = true;
        $pharmacyUser->role = 'pharmacy';
        $pharmacyUser->save();
        $pharmacyUser->syncRoles(['pharmacy']);

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
                'name' => 'صيدلية الشفاء',
                'password' => Hash::make('password'),
                'phone' => '+970598765432',
                'phone_verified_at' => now(),
                'email_verified_at' => now(),
            ]
        );

        $pharmacyUser2->is_active = true;
        $pharmacyUser2->role = 'pharmacy';
        $pharmacyUser2->save();
        $pharmacyUser2->syncRoles(['pharmacy']);

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
