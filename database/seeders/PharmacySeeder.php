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
            ]
        );

        $pharmacyUser->is_active = true;
        $pharmacyUser->role = 'pharmacy';
        $pharmacyUser->email_verified_at = now();
        $pharmacyUser->phone_verified_at = now();
        $pharmacyUser->save();
        $pharmacyUser->syncRoles(['pharmacy']);

        // ✅ استخدام updateOrCreate للصيدلية
        $pharmacy = Pharmacy::updateOrCreate(
            ['pharmacy_custom_id' => 'PH-1234'],
            [
                'pharmacy_name' => 'صيدلية الأمل',
                'address' => 'غزة، شارع الوحدة',
                'latitude' => 31.501600,
                'longitude' => 34.466800,
                'phone_number' => '+970591234567',
            ]
        );
        // الحقول الحساسة تُضبط صراحة بعد الإنشاء (C1: أزيلت من $fillable لمنع التصعيد).
        $pharmacy->user_id = $pharmacyUser->id;
        $pharmacy->is_active = true;
        $pharmacy->avg_rating = 0.00;
        $pharmacy->save();

        // ✅ صيدلية ثانية
        $pharmacyUser2 = User::updateOrCreate(
            ['email' => 'pharmacy2@daway.com'],
            [
                'name' => 'صيدلية الشفاء',
                'password' => Hash::make('password'),
                'phone' => '+970598765432',
            ]
        );

        $pharmacyUser2->is_active = true;
        $pharmacyUser2->role = 'pharmacy';
        $pharmacyUser2->email_verified_at = now();
        $pharmacyUser2->phone_verified_at = now();
        $pharmacyUser2->save();
        $pharmacyUser2->syncRoles(['pharmacy']);

        $pharmacy2 = Pharmacy::updateOrCreate(
            ['pharmacy_custom_id' => 'PH-5678'],
            [
                'pharmacy_name' => 'صيدلية الشفاء',
                'address' => 'نابلس، شارع حطين',
                'latitude' => 32.223800,
                'longitude' => 35.262700,
                'phone_number' => '+970598765432',
            ]
        );
        $pharmacy2->user_id = $pharmacyUser2->id;
        $pharmacy2->is_active = true;
        $pharmacy2->avg_rating = 0.00;
        $pharmacy2->save();
    }
}
