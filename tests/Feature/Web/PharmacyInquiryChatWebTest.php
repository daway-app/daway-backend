<?php

namespace Tests\Feature\Web;

use App\Models\PatientInquiry;
use App\Models\PatientInquiryMessage;
use App\Models\Pharmacy;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PharmacyInquiryChatWebTest extends TestCase
{
    private function pharmacyUserWithPharmacy(): array
    {
        $user = User::factory()->pharmacy()->create();
        $pharmacy = Pharmacy::factory()->create(['user_id' => $user->id]);

        return [$user, $pharmacy];
    }

    public function test_pharmacy_can_open_chat_for_own_inquiry(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $patient = User::factory()->patient()->create();
        $inquiry = PatientInquiry::factory()->create([
            'user_id' => $patient->id,
            'pharmacy_id' => $pharmacy->id,
        ]);

        $this->actingAs($user);

        $response = $this->get(route('pharmacy.inquiries.chat', $inquiry));

        $response->assertOk()
            ->assertSee($patient->name);
    }

    public function test_pharmacy_cannot_open_chat_for_another_pharmacy_inquiry(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $otherPharmacy = Pharmacy::factory()->create();
        $inquiry = PatientInquiry::factory()->create([
            'pharmacy_id' => $otherPharmacy->id,
        ]);

        $this->actingAs($user);

        $this->get(route('pharmacy.inquiries.chat', $inquiry))
            ->assertRedirect(route('pharmacy.inquiries.index'));
    }

    public function test_pharmacy_can_send_chat_message(): void
    {
        Storage::fake('public');

        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $patient = User::factory()->patient()->create();
        $inquiry = PatientInquiry::factory()->create([
            'user_id' => $patient->id,
            'pharmacy_id' => $pharmacy->id,
            'status' => 'new',
        ]);

        $this->actingAs($user);

        $response = $this->post(route('pharmacy.inquiries.chat.send', $inquiry), [
            'message' => 'نعم متوفر',
        ]);

        $response->assertRedirect(route('pharmacy.inquiries.chat', $inquiry));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('patient_inquiry_messages', [
            'patient_inquiry_id' => $inquiry->id,
            'sender_user_id' => $user->id,
            'message' => 'نعم متوفر',
        ]);

        $this->assertDatabaseHas('patient_inquiries', [
            'id' => $inquiry->id,
            'status' => 'answered',
        ]);
    }

    public function test_chat_page_shows_existing_messages(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $patient = User::factory()->patient()->create();
        $inquiry = PatientInquiry::factory()->create([
            'user_id' => $patient->id,
            'pharmacy_id' => $pharmacy->id,
        ]);

        PatientInquiryMessage::factory()->create([
            'patient_inquiry_id' => $inquiry->id,
            'sender_user_id' => $patient->id,
            'message' => 'هل الدواء متوفر؟',
        ]);
        PatientInquiryMessage::factory()->create([
            'patient_inquiry_id' => $inquiry->id,
            'sender_user_id' => $user->id,
            'message' => 'نعم متوفر',
        ]);

        $this->actingAs($user);

        $this->get(route('pharmacy.inquiries.chat', $inquiry))
            ->assertOk()
            ->assertSee('هل الدواء متوفر؟')
            ->assertSee('نعم متوفر');
    }

    public function test_pharmacy_can_send_message_with_image(): void
    {
        Storage::fake('public');

        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $patient = User::factory()->patient()->create();
        $inquiry = PatientInquiry::factory()->create([
            'user_id' => $patient->id,
            'pharmacy_id' => $pharmacy->id,
            'status' => 'new',
        ]);

        $this->actingAs($user);

        $image = UploadedFile::fake()->image('test.png');

        $response = $this->post(route('pharmacy.inquiries.chat.send', $inquiry), [
            'media' => $image,
        ]);

        $response->assertRedirect(route('pharmacy.inquiries.chat', $inquiry));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('patient_inquiry_messages', [
            'patient_inquiry_id' => $inquiry->id,
            'sender_user_id' => $user->id,
            'media_type' => 'image',
        ]);
    }

    public function test_pharmacy_can_fetch_messages_as_json(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $patient = User::factory()->patient()->create();
        $inquiry = PatientInquiry::factory()->create([
            'user_id' => $patient->id,
            'pharmacy_id' => $pharmacy->id,
        ]);

        PatientInquiryMessage::factory()->create([
            'patient_inquiry_id' => $inquiry->id,
            'sender_user_id' => $patient->id,
            'message' => 'هل الدواء متوفر؟',
        ]);

        $this->actingAs($user);

        $response = $this->get(route('pharmacy.inquiries.messages', $inquiry));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');
    }

    public function test_pharmacy_messages_json_marks_unread_as_read(): void
    {
        [$user, $pharmacy] = $this->pharmacyUserWithPharmacy();
        $patient = User::factory()->patient()->create();
        $inquiry = PatientInquiry::factory()->create([
            'user_id' => $patient->id,
            'pharmacy_id' => $pharmacy->id,
        ]);

        $msg = PatientInquiryMessage::factory()->create([
            'patient_inquiry_id' => $inquiry->id,
            'sender_user_id' => $patient->id,
            'message' => 'هل الدواء متوفر؟',
            'read_at' => null,
        ]);

        $this->actingAs($user);

        $this->get(route('pharmacy.inquiries.messages', $inquiry) . '?mark_read=1');

        $msg->refresh();
        $this->assertNotNull($msg->read_at);
    }
}
