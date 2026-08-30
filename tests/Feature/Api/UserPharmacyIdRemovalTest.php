<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * C6: العمود users.pharmacy_id الميت يجب ألا يكون موجوداً بعد الـ migration.
 * + العلاقة pharmacyByCustomId() يجب أن تُزال من User model.
 */
class UserPharmacyIdRemovalTest extends TestCase
{
    public function test_users_table_does_not_have_pharmacy_id_column(): void
    {
        // يتحقق أن العمود محذوف من schema الـ DB (SQLite للاختبارات).
        $this->assertFalse(
            Schema::hasColumn('users', 'pharmacy_id'),
            'users.pharmacy_id يجب أن يُحذف من schema الـ DB (C6).'
        );
    }

    public function test_user_model_does_not_expose_pharmacyByCustomId(): void
    {
        // C6: العلاقة الميتة أزيلت من الـ Model.
        $user = User::factory()->pharmacy()->create();
        $this->assertFalse(
            method_exists($user, 'pharmacyByCustomId'),
            'User::pharmacyByCustomId() يجب أن يُحذف (C6).'
        );
    }

    public function test_user_fillable_excludes_sensitive_fields(): void
    {
        // C1: الحقول الحساسة (role, is_active, must_change_password) خارج $fillable.
        $user = new User;
        $fillable = $user->getFillable();

        $this->assertNotContains('role', $fillable, 'C1: role يجب ألا يكون fillable');
        $this->assertNotContains('is_active', $fillable, 'C1: is_active يجب ألا يكون fillable');
        $this->assertNotContains('must_change_password', $fillable, 'C1: must_change_password يجب ألا يكون fillable');
        $this->assertNotContains('pharmacy_id', $fillable, 'C6: pharmacy_id يجب ألا يكون fillable');
    }

    public function test_patient_profile_update_does_not_accept_role_escalation(): void
    {
        // C1: حتى لو أرسل attacker حقل role في payload، الـ fillable يرفضه.
        $patient = User::factory()->patient()->create();
        Sanctum::actingAs($patient);

        $response = $this->postJson('/api/profile/patient', [
            'name' => 'New Name',
            'role' => 'admin',        // محاولة تصعيد — يجب أن تُتجاهل
            'is_active' => true,      // محاولة تفعيل — يجب أن تُتجاهل
        ]);

        $response->assertOk();
        $this->assertSame('New Name', $patient->fresh()->name);
        $this->assertSame('patient', $patient->fresh()->role, 'role يجب ألا يتغير عبر mass-assign');
        $this->assertTrue($patient->fresh()->is_active, 'is_active (true) محايد — لا تغيير عبر mass-assign');
    }
}
