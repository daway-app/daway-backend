<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Pharmacy;
use App\Models\PharmacyHour;
use App\Models\ActiveIngredient;
use App\Models\Medicine;
use App\Models\PharmacyMedicine;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ===== 1. إنشاء الأدمن (Admin) =====
        $admin = User::create([
            'name' => 'مدير النظام',
            'phone' => '0599999999',
            'email' => 'admin@daway.com',
            'password' => Hash::make('admin123'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        // ===== 2. إنشاء الصيدلية (Pharmacy) =====
        $pharmacyUser = User::create([
            'name' => 'صيدلية الشفاء',
            'phone' => '0598888888',
            'email' => 'pharmacy@daway.com',
            'password' => Hash::make('pharmacy123'),
            'role' => 'pharmacy',
            'pharmacy_id' => 'PH-001',
            'is_active' => true,
        ]);

        $pharmacy = Pharmacy::create([
            'user_id' => $pharmacyUser->id,
            'pharmacy_custom_id' => 'PH-001',
            'pharmacy_name' => 'صيدلية الشفاء',
            'address' => 'رام الله - فلسطين',
            'latitude' => 31.9539,
            'longitude' => 35.9105,
            'phone_number' => '0598888888',
            'avg_rating' => 4.5,
            'is_active' => true,
        ]);

        // إضافة ساعات العمل
        foreach (['sat', 'sun', 'mon', 'tue', 'wed', 'thu'] as $day) {
            PharmacyHour::create([
                'pharmacy_id' => $pharmacy->id,
                'day' => $day,
                'opening_time' => '09:00',
                'closing_time' => '21:00',
            ]);
        }
        PharmacyHour::create([
            'pharmacy_id' => $pharmacy->id,
            'day' => 'fri',
            'opening_time' => '14:00',
            'closing_time' => '20:00',
        ]);

        // ===== 3. إنشاء مريض (Patient) =====
        // ملاحظة: تمت إزالة الحقول (address, birth_date, emergency_contact)
        // لأنها غير موجودة في هجرة users الجديدة.
        User::create([
            'name' => 'أحمد المريض',
            'phone' => '0597777777',
            'role' => 'patient',
            'is_active' => true,
        ]);

        // ===== 4. إنشاء مواد فعالة وأدوية =====
        $paracetamol = ActiveIngredient::create([
            'name' => 'باراسيتامول',
            'description' => 'مسكن وخافض حرارة شائع الاستخدام'
        ]);

        $ibuprofen = ActiveIngredient::create([
            'name' => 'ايبوبروفين',
            'description' => 'مسكن ومضاد التهاب'
        ]);

        $panadol = Medicine::create([
            'trade_name' => 'بنادول أدفانس',
            'active_ingredient_id' => $paracetamol->id,
            'description' => 'مسكن سريع للصداع والألم',
            'image' => null
        ]);

        $adol = Medicine::create([
            'trade_name' => 'أدول 500',
            'active_ingredient_id' => $paracetamol->id,
            'description' => 'مسكن خفيف للألم',
            'image' => null
        ]);

        $brufen = Medicine::create([
            'trade_name' => 'بروفين 400',
            'active_ingredient_id' => $ibuprofen->id,
            'description' => 'مضاد التهابات ومسكن قوي',
            'image' => null
        ]);

        // ===== 5. إضافة أدوية لمخزون الصيدلية =====
        PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $panadol->id,
            'price' => 12.50,
            'quantity' => 100,
            'is_available' => true,
        ]);

        PharmacyMedicine::create([
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $adol->id,
            'price' => 8.00,
            'quantity' => 50,
            'is_available' => true,
        ]);

        $this->command->info('✅ تم إنشاء البيانات بنجاح!');
        $this->command->info('👤 أدمن: admin@daway.com / admin123');
        $this->command->info('🏥 صيدلية: PH-001 / pharmacy123');
    }
}
