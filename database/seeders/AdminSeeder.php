<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * تزويد حساب الأدمن الأولي — **آمن للإنتاج** (Phase F1).
 *
 * ── ما تغيّر ولماذا ────────────────────────────────────────────────
 * النسخة السابقة كانت:
 *   · تُنشئ الأدمن بكلمة مرور **مكتوبة في الكود** (`Admin@12345`) ⇒ أي قاعدة
 *     جديدة تُنشر بكلمة مرور معروفة علنًا في المستودع.
 *   · تُعيد في كل تشغيل ضبط `role=admin` و`is_active=true` و`syncRoles` ⇒
 *     أدمن عُطِّل بقرار أمني يُعاد تفعيله تلقائيًا عند أول deploy تالٍ.
 *
 * ── السلوك الحالي ─────────────────────────────────────────────────
 *  1. الحساب موجود (بأي حالة/دور) ⇒ **لا يُلمس إطلاقًا**. لا كلمة مرور،
 *     لا تفعيل، لا صلاحيات. القرار يبقى للبشر.
 *  2. الحساب غير موجود و`ADMIN_INITIAL_PASSWORD` مضبوط ⇒ إنشاء واحد فقط
 *     بكلمة المرور من البيئة + `must_change_password=true`.
 *  3. الحساب غير موجود والكلمة غير مضبوطة ⇒ **لا إنشاء** + تحذير. لا رجوع
 *     إلى أي قيمة افتراضية.
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) config('app.admin_email');

        $existing = User::where('email', $email)->first();

        if ($existing !== null) {
            // 🔴 لا تعديل على حساب قائم — هذه هي القاعدة الأمنية الأساسية.
            $this->command?->info("AdminSeeder: الحساب {$email} موجود — لم يُعدَّل شيء (لا دور ولا تفعيل ولا كلمة مرور).");

            return;
        }

        $password = (string) config('app.admin_initial_password');

        if ($password === '') {
            $this->command?->warn(
                'AdminSeeder: ADMIN_INITIAL_PASSWORD غير مضبوط — لم يُنشأ أدمن. '
                .'اضبطه في بيئة النشر ثم أعد تشغيل الـseeder يدويًا.'
            );

            return;
        }

        $admin = User::unguarded(fn () => User::create([
            'name' => 'Admin',
            'email' => $email,
            'password' => Hash::make($password),
            // phone فريد وقد يكون محجوزًا لمستخدم حقيقي — تركه NULL أأمن.
            'phone' => null,
            'phone_verified_at' => now(),
            'email_verified_at' => now(),
            'role' => 'admin',
            'must_change_password' => true,
        ]));

        $admin->syncRoles(['admin']);

        $this->command?->info(
            "AdminSeeder: أُنشئ الحساب {$email} بكلمة المرور من البيئة — يجب تغييرها عند أول دخول."
        );
    }
}
