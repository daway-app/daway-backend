<?php

namespace Database\Seeders;

use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyMedicine;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PharmacySeeder extends Seeder
{
    public function run(): void
    {
        // ✅ استخدام updateOrCreate عشان ما يكرر
        $pharmacyUser = $this->pharmacyUser('pharmacy@daway.com', 'صيدلية الأمل', '+970591234567');

        Pharmacy::unguarded(fn () => Pharmacy::updateOrCreate(
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
                'profile_completed_at' => now(),
            ]
        ));

        // ✅ صيدلية ثانية
        $pharmacyUser2 = $this->pharmacyUser('pharmacy2@daway.com', 'صيدلية الشفاء', '+970598765432');

        Pharmacy::unguarded(fn () => Pharmacy::updateOrCreate(
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
                'profile_completed_at' => now(),
            ]
        ));

        // ⚠️ PRODUCTION-SAFE: Demo inventory (PH-2001–PH-2010) is skipped in production
        // environments to prevent seeding fake pharmacy_medicines on every deploy.
        // Local/test environments retain demo data for development.
        if (app()->environment('local', 'testing')) {
            $this->seedDemoPharmacies();
        }
    }

    /**
     * إنشاء الصيدليات التجريبية والمخزون الخاص بها (PH-2001–PH-2010).
     * يُستدعى فقط في بيئات local/testing — لا يعمل في production.
     */
    private function seedDemoPharmacies(): void
    {
        $medicinesByName = Medicine::whereIn('trade_name', [
            'Panadol', 'Amoxil', 'Glucophage', 'Ventolin', 'Augmentin',
        ])->get()->keyBy('trade_name');

        $demo = [
            [
                'custom_id' => 'PH-2001',
                'name' => 'صيدلية الرحمة',
                'email' => 'pharmacy3@daway.com',
                'phone' => '+970590111201',
                'address' => 'غزة، حي الشيخ رضوان، شارع الرشيد',
                'region' => 'غزة – الشيخ رضوان',
                'lat' => 31.521544, 'lng' => 34.483021,
                'rating' => 4.6,
            ],
            [
                'custom_id' => 'PH-2002',
                'name' => 'صيدلية الحياة',
                'email' => 'pharmacy4@daway.com',
                'phone' => '+970590111202',
                'address' => 'غزة، جباليا الشمالية، مقابل سوق جباليا القديم',
                'region' => 'شمال غزة – جباليا',
                'lat' => 31.533072, 'lng' => 34.507089,
                'rating' => 4.2,
            ],
            [
                'custom_id' => 'PH-2003',
                'name' => 'صيدلية النور',
                'email' => 'pharmacy5@daway.com',
                'phone' => '+970590111203',
                'address' => 'غزة، حي الشجاعية، شارع عبلة',
                'region' => 'غزة – الشجاعية',
                'lat' => 31.508086, 'lng' => 34.489154,
                'rating' => 4.8,
            ],
            [
                'custom_id' => 'PH-2004',
                'name' => 'صيدلية القدس',
                'email' => 'pharmacy6@daway.com',
                'phone' => '+970590111204',
                'address' => 'غزة، حي النصر، شارع عمر المختار',
                'region' => 'غزة – النصر',
                'lat' => 31.515048, 'lng' => 34.464336,
                'rating' => 4.4,
            ],
            [
                'custom_id' => 'PH-2005',
                'name' => 'صيدلية الفلاح',
                'email' => 'pharmacy7@daway.com',
                'phone' => '+970590111205',
                'address' => 'غزة، حي الزيتون، شارع صلاح الدين',
                'region' => 'غزة – الزيتون',
                'lat' => 31.496833, 'lng' => 34.477192,
                'rating' => 4.0,
            ],
            [
                'custom_id' => 'PH-2006',
                'name' => 'صيدلية ابن سينا',
                'email' => 'pharmacy8@daway.com',
                'phone' => '+970590111206',
                'address' => 'خانيونس، شارع البيضاء',
                'region' => 'خانيونس',
                'lat' => 31.346921, 'lng' => 34.306268,
                'rating' => 4.7,
            ],
            [
                'custom_id' => 'PH-2007',
                'name' => 'صيدلية الأمانة',
                'email' => 'pharmacy9@daway.com',
                'phone' => '+970590111207',
                'address' => 'رفح، شارع جمال عبد الناصر',
                'region' => 'رفح',
                'lat' => 31.290041, 'lng' => 34.250208,
                'rating' => 4.3,
            ],
            [
                'custom_id' => 'PH-2008',
                'name' => 'صيدلية المركز الطبي',
                'email' => 'pharmacy10@daway.com',
                'phone' => '+970590111208',
                'address' => 'دير البلح، شارع الساحل',
                'region' => 'الوسطى – دير البلح',
                'lat' => 31.418444, 'lng' => 34.352272,
                'rating' => 4.5,
            ],
            [
                'custom_id' => 'PH-2009',
                'name' => 'صيدلية السلام',
                'email' => 'pharmacy12@daway.com',
                'phone' => '+970590111209',
                'address' => 'غزة، حي الطفاح، شرق الشجاعية',
                'region' => 'غزة – الطفاح',
                'lat' => 31.496057, 'lng' => 34.523018,
                'rating' => 3.9,
            ],
            [
                'custom_id' => 'PH-2010',
                'name' => 'صيدلية رمال',
                'email' => 'pharmacy11@daway.com',
                'phone' => '+970590111210',
                'address' => 'غزة، حي ريمال الجنوبي، شارع جناح',
                'region' => 'غزة – ريمال',
                'lat' => 31.505912, 'lng' => 34.445331,
                'rating' => 4.9,
            ],
        ];

        // المخزون لكل صيدلية: [اسم الدواء => [سعر، كمية، متاح، حد أدنى]]
        // تشابك واقعي: البانادول موجود في الجميع تقريباً، مضادات البكتيريا شائعة،
        // والـ Glucophage/Ventolin نوادر (في 4/3 صيدليات فقط وبأسعار متباينة).
        $inventories = [
            'PH-2001' => [ // الرحمة — كامل ومتوسط الأسعار
                'Panadol' => [12.00, 850, true, 40],
                'Amoxil' => [18.50, 120, true, 15],
                'Glucophage' => [22.00, 60, true, 10],
                'Ventolin' => [28.00, 20, true, 5],
                'Augmentin' => [45.00, 35, true, 10],
            ],
            'PH-2002' => [ // الحياة — أرخص من المتوسط، ناقص Glucophage
                'Panadol' => [10.50, 640, true, 30],
                'Amoxil' => [16.00, 90, true, 15],
                'Ventolin' => [25.50, 8, true, 5],
                'Augmentin' => [41.00, 12, true, 10],
            ],
            'PH-2003' => [ // النور — وافر وفيه استثناء متاح=مخفي
                'Panadol' => [13.00, 1000, true, 50],
                'Amoxil' => [20.00, 200, true, 20],
                'Glucophage' => [21.00, 80, true, 15],
                'Ventolin' => [27.00, 45, true, 10],
                'Augmentin' => [47.00, 18, false, 10], // موجود من الصرف غير مدار حالياً
            ],
            'PH-2004' => [ // القدس — متوسط، Ventolin نادر
                'Panadol' => [11.75, 430, true, 25],
                'Amoxil' => [19.00, 60, true, 10],
                'Augmentin' => [44.00, 22, true, 10],
            ],
            'PH-2005' => [ // الفلاح — مخزون منخفض
                'Panadol' => [12.50, 90, true, 40],
                'Amoxil' => [17.50, 25, true, 15],
                'Glucophage' => [23.50, 0, false, 10], // صرف كاملاً
                'Augmentin' => [43.00, 7, true, 10],
            ],
            'PH-2006' => [ // ابن سينا — متميز بالتوافر والتنوع
                'Panadol' => [11.00, 950, true, 40],
                'Amoxil' => [15.50, 260, true, 20],
                'Glucophage' => [20.00, 140, true, 15],
                'Ventolin' => [24.00, 55, true, 10],
                'Augmentin' => [39.50, 85, true, 15],
            ],
            'PH-2007' => [ // الأمانة — جنوبية بسعر أعلى
                'Panadol' => [14.00, 310, true, 30],
                'Amoxil' => [21.50, 75, true, 15],
                'Ventolin' => [30.00, 15, true, 5],
                'Augmentin' => [49.00, 20, true, 10],
            ],
            'PH-2008' => [ // المركز الطبي — متخصص مزمن
                'Panadol' => [12.25, 400, true, 30],
                'Glucophage' => [19.50, 175, true, 20],
                'Amoxil' => [18.00, 110, true, 15],
            ],
            'PH-2009' => [ // السلام — شبه فارغ ولديه بطاقة منتهية
                'Panadol' => [15.50, 30, true, 40], // تحت الحد الأدنى — تنبيه مخزون
                'Amoxil' => [19.00, 0, false, 15],
                'Ventolin' => [29.00, 5, true, 5],
            ],
            'PH-2010' => [ // رمال — فاخرة في وسط غزة
                'Panadol' => [16.00, 700, true, 50],
                'Amoxil' => [22.00, 180, true, 20],
                'Glucophage' => [25.00, 95, true, 15],
                'Ventolin' => [32.00, 40, true, 10],
                'Augmentin' => [52.00, 60, true, 15],
            ],
        ];

        foreach ($demo as $row) {
            $user = $this->pharmacyUser($row['email'], $row['name'], $row['phone']);

            Pharmacy::unguarded(fn () => Pharmacy::updateOrCreate(
                ['pharmacy_custom_id' => $row['custom_id']],
                [
                    'user_id' => $user->id,
                    'pharmacy_custom_id' => $row['custom_id'],
                    'pharmacy_name' => $row['name'],
                    'address' => $row['address'],
                    'region' => $row['region'],
                    'latitude' => $row['lat'],
                    'longitude' => $row['lng'],
                    'phone_number' => $row['phone'],
                    'is_active' => true,
                    'avg_rating' => $row['rating'],
                    // مطلوب لتمرير middleware profile.complete في لوحة الويب
                    'profile_completed_at' => now(),
                ]
            ));

            $pharmacy = Pharmacy::where('pharmacy_custom_id', $row['custom_id'])->first();
            if ($pharmacy === null) {
                continue;
            }

            // firstOrCreate: لا تُعِد ضبط مخزون حدثه المستخدم فعلاً على الإنتاج
            foreach ($inventories[$row['custom_id']] ?? [] as $tradeName => [$price, $quantity, $available, $minStock]) {
                $medicine = $medicinesByName->get($tradeName);
                if ($medicine === null) {
                    continue; // يُشغَّل بعد MedicineSeeder — احتياط فقط
                }

                PharmacyMedicine::firstOrCreate(
                    ['pharmacy_id' => $pharmacy->id, 'medicine_id' => $medicine->id],
                    [
                        'price' => $price,
                        'quantity' => $quantity,
                        'is_available' => $available,
                        'min_stock' => $minStock,
                    ]
                );
            }
        }
    }

    /**
     * مستخدم صيدلية تجريبي — كلمة السر تُضبط عند الإنشاء فقط.
     * إعادة تشغيل الـ seeder (مثل boot في كل deploy على الإنتاج) لا
     * تعيد تعيين كلمات السر ولا تسحق تعديلات المستخدمين اللاحقة.
     */
    private function pharmacyUser(string $email, string $name, string $phone): User
    {
        $user = User::where('email', $email)->first();

        if ($user === null) {
            $user = User::create([
                'email' => $email,
                'name' => $name,
                'password' => Hash::make('password'),
                'phone' => $phone,
            ]);
            $user->role = 'pharmacy';
            $user->is_active = true;
            $user->email_verified_at = now();
            $user->phone_verified_at = now();
            $user->save();

            // قد لا تكون أدوار Spatie موجودة في قاعدة بيانات لم تشغّل
            // RolePermissionSeeder — لا نُسقط الـ seeder بسببها
            if (\Spatie\Permission\Models\Role::where('name', 'pharmacy')->exists()) {
                $user->syncRoles(['pharmacy']);
            }

            return $user;
        }

        // ضمان الدور والتفعيل فقط — بلا مس بكلمة السر أو البروفايل
        if ($user->role !== 'pharmacy') {
            $user->role = 'pharmacy';
            $user->is_active = true;
            $user->email_verified_at = $user->email_verified_at ?? now();
            $user->phone_verified_at = $user->phone_verified_at ?? now();
            $user->save();
        }

        if (\Spatie\Permission\Models\Role::where('name', 'pharmacy')->exists()
            && ! $user->hasRole('pharmacy')) {
            $user->syncRoles(['pharmacy']);
        }

        return $user;
    }
}
