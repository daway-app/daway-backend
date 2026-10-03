<?php

namespace Tests\Feature\Web;

use App\Models\Medicine;
use App\Models\PatientInquiry;
use App\Models\Pharmacy;
use App\Models\User;
use Tests\TestCase;

class PatientInquiryTest extends TestCase
{
    private function pharmacyUserWithPharmacy(): array
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id]);

        return [$user, $pharmacy];
    }

    public function test_patient_can_create_inquiry_and_pharmacy_gets_notification(): void
    {
        [$pharmacyUser, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $patient = User::factory()->patient()->create();
        $medicine = Medicine::factory()->create();

        $this->actingAs($patient);

        $response = $this->post(route('patient.inquiries.store'), [
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'message' => 'هل هذا الدواء متوفر؟',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('patient_inquiries', [
            'user_id' => $patient->id,
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'status' => 'new',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $pharmacyUser->id,
            'medicine_id' => $medicine->id,
            'type' => 'new_inquiry',
        ]);
    }

    public function test_pharmacy_can_update_inquiry_status(): void
    {
        [$pharmacyUser, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $patient = User::factory()->patient()->create();
        $medicine = Medicine::factory()->create();

        $inquiry = PatientInquiry::factory()->create([
            'user_id' => $patient->id,
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => $medicine->id,
            'status' => 'new',
        ]);

        $this->actingAs($pharmacyUser);

        $this->put(route('pharmacy.inquiries.update', $inquiry), [
            'status' => 'answered',
        ])->assertRedirect(route('pharmacy.inquiries.index'));

        $this->assertDatabaseHas('patient_inquiries', [
            'id' => $inquiry->id,
            'status' => 'answered',
        ]);
    }

    /** المراسلة المباشرة من الخريطة (ويب): بلا دواء — لازم تنجح. */
    public function test_patient_can_create_inquiry_without_medicine(): void
    {
        [$pharmacyUser, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $patient = User::factory()->patient()->create();

        $this->actingAs($patient);

        $this->post(route('patient.inquiries.store'), [
            'pharmacy_id' => $pharmacy->id,
            'message' => 'مرحبا، بدي أسأل',
        ])->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('patient_inquiries', [
            'user_id' => $patient->id,
            'pharmacy_id' => $pharmacy->id,
            'medicine_id' => null,
            'status' => 'new',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $pharmacyUser->id,
            'medicine_id' => null,
            'type' => 'new_inquiry',
        ]);
    }

    /** pharmacy_id يبقى إلزاميًا (ويب). */
    public function test_web_creation_rejects_missing_pharmacy(): void
    {
        $patient = User::factory()->patient()->create();

        $this->actingAs($patient);

        $this->post(route('patient.inquiries.store'), [
            'message' => 'بلا صيدلية',
        ])->assertSessionHasErrors('pharmacy_id');

        $this->assertDatabaseCount('patient_inquiries', 0);
    }
}
