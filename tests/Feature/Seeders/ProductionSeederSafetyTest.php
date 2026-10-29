<?php

namespace Tests\Feature\Seeders;

use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\User;
use Database\Seeders\AdminSeeder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\MedicineSeeder;
use Database\Seeders\PharmacySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase F1 — سلامة الـseeders على بيئة الإنتاج.
 *
 * القاعدة التي يحميها الملف: **تشغيل الـseeder مرتين لا يغيّر بيانات قائمة**.
 * كان هذا مكسورًا فعليًا:
 *  · `AdminSeeder` بكلمة مرور مكتوبة في الكود + إعادة تفعيل/فرض الدور كل تشغيل.
 *  · `PharmacySeeder` ينشئ صيدوليتين تجريبيتين بكلمة `password` في الإنتاج.
 *  · `MedicineSeeder` يعيد ضبط `stock`/`is_available` لصفوف حقيقية.
 */
final class ProductionSeederSafetyTest extends TestCase
{
    private function runSeeder(string $class): void
    {
        Artisan::call('db:seed', ['--class' => $class, '--force' => true]);
    }

    // ── AdminSeeder ────────────────────────────────────────────────

    public function test_admin_seeder_never_touches_an_existing_account(): void
    {
        $admin = User::factory()->admin()->create([
            'email' => config('app.admin_email'),
            'password' => Hash::make('owner-chosen-password'),
            'is_active' => false, // عُطِّل بقرار بشري
        ]);
        $admin->syncRoles(['admin']);

        // حتى مع وجود كلمة مرور بيئية، لا يجوز أن يلمس الحساب القائم.
        config(['app.admin_initial_password' => 'env-initial-password']);

        $this->runSeeder(AdminSeeder::class);

        $admin->refresh();

        $this->assertTrue(
            Hash::check('owner-chosen-password', $admin->password),
            'كلمة مرور حساب قائم يجب ألا تتغير'
        );
        $this->assertFalse((bool) $admin->is_active, 'أدمن معطَّل يجب ألا يُعاد تفعيله تلقائيًا');
        $this->assertSame('admin', $admin->role);
    }

    public function test_admin_seeder_does_not_force_admin_role_on_an_existing_account(): void
    {
        // حساب بنفس بريد الأدمن لكن بدور مختلف — لا يُحوَّل قسرًا.
        $user = User::factory()->patient()->create([
            'email' => config('app.admin_email'),
        ]);

        $this->runSeeder(AdminSeeder::class);

        $user->refresh();
        $this->assertSame('patient', $user->role);
        $this->assertFalse($user->hasRole('admin'));
    }

    public function test_admin_seeder_creates_the_account_only_from_the_env_password(): void
    {
        config([
            'app.admin_email' => 'ops@example.test',
            'app.admin_initial_password' => 'env-strong-password-1',
        ]);

        $this->runSeeder(AdminSeeder::class);

        $admin = User::where('email', 'ops@example.test')->first();

        $this->assertNotNull($admin, 'الأدمن يجب أن يُنشأ عند ضبط كلمة مرور البيئة');
        $this->assertTrue(Hash::check('env-strong-password-1', $admin->password));
        $this->assertSame('admin', $admin->role);
        $this->assertTrue($admin->hasRole('admin'));
        $this->assertTrue((bool) $admin->must_change_password, 'كلمة المرور الأولية تُلزم التغيير');
    }

    public function test_admin_seeder_creates_nothing_when_the_env_password_is_missing(): void
    {
        config([
            'app.admin_email' => 'ops@example.test',
            'app.admin_initial_password' => null,
        ]);

        $this->runSeeder(AdminSeeder::class);

        $this->assertNull(
            User::where('email', 'ops@example.test')->first(),
            'بلا ADMIN_INITIAL_PASSWORD لا يُنشأ أدمن — ولا رجوع إلى كلمة مرور ثابتة'
        );
    }

    public function test_admin_seeder_is_stable_across_repeated_runs(): void
    {
        config([
            'app.admin_email' => 'ops@example.test',
            'app.admin_initial_password' => 'env-strong-password-1',
        ]);

        $this->runSeeder(AdminSeeder::class);
        $first = User::where('email', 'ops@example.test')->firstOrFail()->password;

        // المستخدم يغيّر كلمة المرور لاحقًا
        $user = User::where('email', 'ops@example.test')->firstOrFail();
        $user->password = Hash::make('changed-by-owner');
        $user->must_change_password = false;
        $user->save();

        $this->runSeeder(AdminSeeder::class);
        $this->runSeeder(AdminSeeder::class);

        $user->refresh();
        $this->assertTrue(Hash::check('changed-by-owner', $user->password), 'إعادة التشغيل لا تعيد كلمة المرور الأولية');
        $this->assertFalse((bool) $user->must_change_password);
        $this->assertNotSame($first, $user->password);
    }

    // ── PharmacySeeder ────────────────────────────────────────────

    public function test_pharmacy_seeder_creates_nothing_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->runSeeder(MedicineSeeder::class);
        $this->runSeeder(PharmacySeeder::class);

        $this->assertSame(0, Pharmacy::count(), 'لا صيدليات تجريبية في الإنتاج');
        $this->assertSame(
            0,
            User::whereIn('email', ['pharmacy@daway.com', 'pharmacy2@daway.com'])->count(),
            'لا حسابات بكلمة مرور افتراضية في الإنتاج'
        );
    }

    public function test_pharmacy_seeder_still_provisions_demo_data_locally(): void
    {
        $this->app['env'] = 'testing';

        $this->runSeeder(MedicineSeeder::class);
        $this->runSeeder(PharmacySeeder::class);

        $this->assertTrue(Pharmacy::where('pharmacy_custom_id', 'PH-1234')->exists());
        $this->assertTrue(Pharmacy::where('pharmacy_custom_id', 'PH-2001')->exists());
    }

    // ── MedicineSeeder ────────────────────────────────────────────

    public function test_medicine_seeder_does_not_reset_stock_of_existing_medicines(): void
    {
        $medicine = Medicine::create([
            'trade_name' => 'Panadol',
            'active_ingredient' => 'Paracetamol',
            'description' => 'edited by the team',
            'is_available' => false,
            'stock' => 3,
        ]);

        $this->runSeeder(MedicineSeeder::class);

        $medicine->refresh();

        $this->assertSame(3, (int) $medicine->stock, 'مخزون قائم لا يُعاد ضبطه');
        $this->assertFalse((bool) $medicine->is_available, 'حالة التوفر القائمة لا تُغيَّر');
        $this->assertSame('edited by the team', $medicine->description);

        // والبقية تُنشأ طبيعيًا
        $this->assertTrue(Medicine::where('trade_name', 'Amoxil')->exists());
    }

    // ── التشغيل المتكرر لكل الـseeders ────────────────────────────

    public function test_running_all_seeders_twice_keeps_production_data_stable(): void
    {
        $this->app['env'] = 'production';
        config([
            'app.admin_email' => 'ops@example.test',
            'app.admin_initial_password' => 'env-strong-password-1',
        ]);

        $classes = [
            RolePermissionSeeder::class,
            AdminSeeder::class,
            CategorySeeder::class,
            MedicineSeeder::class,
            PharmacySeeder::class,
        ];

        foreach ($classes as $class) {
            $this->runSeeder($class);
        }

        $snapshot = [
            'users' => User::count(),
            'categories' => \App\Models\Category::count(),
            'medicines' => Medicine::count(),
            'pharmacies' => Pharmacy::count(),
            'admin_password' => User::where('email', 'ops@example.test')->value('password'),
        ];

        foreach ($classes as $class) {
            $this->runSeeder($class);
        }

        $this->assertSame($snapshot['users'], User::count(), 'عدد المستخدمين مستقر');
        $this->assertSame($snapshot['categories'], \App\Models\Category::count(), 'عدد الأقسام مستقر');
        $this->assertSame($snapshot['medicines'], Medicine::count(), 'عدد الأدوية مستقر');
        $this->assertSame($snapshot['pharmacies'], Pharmacy::count(), 'عدد الصيدليات مستقر');
        $this->assertSame(
            $snapshot['admin_password'],
            User::where('email', 'ops@example.test')->value('password'),
            'كلمة مرور الأدمن لا تتغير بإعادة البذر'
        );
    }
}
