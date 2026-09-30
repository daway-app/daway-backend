<?php

namespace Tests\Feature\Api;

use App\Models\PatientInquiry;
use App\Models\Pharmacy;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PharmacyInquiryApiTest extends TestCase
{
    private function pharmacyUserWithPharmacy(): array
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id]);

        return [$user, $pharmacy];
    }

    public function test_pharmacy_can_list_inquiries(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();

        PatientInquiry::factory()->create(['pharmacy_id' => $pharmacy->id, 'status' => 'new']);
        PatientInquiry::factory()->create(['pharmacy_id' => $pharmacy->id, 'status' => 'answered']);
        PatientInquiry::factory()->create(['pharmacy_id' => $pharmacy->id, 'status' => 'closed']);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/pharmacy/inquiries');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('counts.new', 1)
            ->assertJsonPath('counts.answered', 1)
            ->assertJsonPath('counts.closed', 1)
            ->assertJsonStructure([
                'data' => [['id', 'status', 'user' => ['id', 'name']]],
                'counts' => ['new', 'answered', 'closed'],
                'pagination' => ['total', 'per_page', 'current_page', 'last_page'],
            ])
            ->assertJsonPath('pagination.total', 3);
    }

    public function test_pharmacy_inquiries_return_distinct_user_ids_for_same_name_patients(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();

        // مريضان بنفس الاسم لكن مع user.id مختلف — يجب أن يُرجع التطبيق معرفيهما الحقيقيين
        $patientA = User::factory()->patient()->create(['name' => 'محمد أحمد']);
        $patientB = User::factory()->patient()->create(['name' => 'محمد أحمد']);

        $inquiryA = PatientInquiry::factory()->create(['pharmacy_id' => $pharmacy->id, 'user_id' => $patientA->id]);
        $inquiryB = PatientInquiry::factory()->create(['pharmacy_id' => $pharmacy->id, 'user_id' => $patientB->id]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/pharmacy/inquiries');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');

        $ids = [];
        foreach ($response->json('data') as $item) {
            $this->assertSame('محمد أحمد', $item['user']['name']);
            $ids[] = $item['user']['id'];
        }

        $this->assertSame($inquiryA->user_id, $ids[0]);
        $this->assertSame($inquiryB->user_id, $ids[1]);
        $this->assertCount(2, array_unique($ids), 'Same-name patients must not collapse into one user id');
    }

    public function test_pharmacy_can_show_inquiry(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $inquiry = PatientInquiry::factory()->create(['pharmacy_id' => $pharmacy->id]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/pharmacy/inquiries/{$inquiry->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $inquiry->id)
            ->assertJsonStructure(['data' => ['id', 'status', 'message', 'created_at']]);
    }

    public function test_pharmacy_can_update_inquiry_status(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $inquiry = PatientInquiry::factory()->create(['pharmacy_id' => $pharmacy->id, 'status' => 'new']);

        Sanctum::actingAs($user);

        $response = $this->putJson("/api/pharmacy/inquiries/{$inquiry->id}", [
            'status' => 'answered',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'answered');

        $this->assertDatabaseHas('patient_inquiries', [
            'id' => $inquiry->id,
            'status' => 'answered',
        ]);
    }

    public function test_pharmacy_cannot_access_another_pharmacy_inquiry(): void
    {
        [$userA, $pharmacyA] = $this->pharmacyUserWithPharmacy();
        [$userB] = $this->pharmacyUserWithPharmacy();
        $inquiryA = PatientInquiry::factory()->create(['pharmacy_id' => $pharmacyA->id]);

        Sanctum::actingAs($userB);

        $this->getJson("/api/pharmacy/inquiries/{$inquiryA->id}")->assertForbidden();
    }

    public function test_patient_cannot_list_pharmacy_inquiries(): void
    {
        $patient = User::factory()->patient()->create();

        Sanctum::actingAs($patient);

        $this->getJson('/api/pharmacy/inquiries')->assertForbidden();
    }
}
