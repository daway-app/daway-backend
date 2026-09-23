<?php

namespace Tests\Feature\Api\Patient;

use App\Models\Address;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddressApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->patient = User::factory()->patient()->create();
        $this->actingAs($this->patient, 'sanctum');
    }

    public function test_patient_can_list_own_addresses(): void
    {
        Address::factory()->count(3)->for($this->patient)->create();

        $response = $this->getJson('/api/patient/addresses');

        $response->assertStatus(200)
            ->assertJsonFragment(['success' => true])
            ->assertJsonCount(3, 'data');
    }

    public function test_patient_can_create_address(): void
    {
        $data = [
            'recipient_name' => 'Test Recipient',
            'address' => 'Test Address Line 1',
            'phone' => '0591234567',
        ];

        $response = $this->postJson('/api/patient/addresses', $data);

        $response->assertStatus(201)
            ->assertJsonFragment(['success' => true]);

        $this->assertDatabaseHas('addresses', [
            'user_id' => $this->patient->id,
            'recipient_name' => 'Test Recipient',
        ]);
    }

    public function test_patient_can_show_own_address(): void
    {
        $address = Address::factory()->for($this->patient)->create();

        $response = $this->getJson("/api/patient/addresses/{$address->id}");

        $response->assertStatus(200)
            ->assertJsonFragment(['success' => true])
            ->assertJsonFragment(['id' => $address->id]);
    }

    public function test_patient_can_update_own_address(): void
    {
        $address = Address::factory()->for($this->patient)->create();

        $response = $this->putJson("/api/patient/addresses/{$address->id}", [
            'recipient_name' => 'Updated Name',
            'address' => 'Updated Address',
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['success' => true]);

        $this->assertDatabaseHas('addresses', [
            'id' => $address->id,
            'recipient_name' => 'Updated Name',
        ]);
    }

    public function test_patient_can_delete_own_address(): void
    {
        $address = Address::factory()->for($this->patient)->create();

        $response = $this->deleteJson("/api/patient/addresses/{$address->id}");

        $response->assertStatus(200)
            ->assertJsonFragment(['success' => true]);

        $this->assertDatabaseMissing('addresses', ['id' => $address->id]);
    }

    public function test_default_address_behavior(): void
    {
        $address1 = Address::factory()->for($this->patient)->create(['is_default' => false]);
        $address2 = Address::factory()->for($this->patient)->create(['is_default' => true]);

        $response = $this->getJson('/api/patient/addresses');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertTrue($data[0]['is_default'] >= $data[1]['is_default']);
    }

    public function test_patient_cannot_access_another_users_address(): void
    {
        $otherUser = User::factory()->patient()->create();
        $address = Address::factory()->for($otherUser)->create();

        $response = $this->getJson("/api/patient/addresses/{$address->id}");

        $response->assertStatus(403);
    }

    public function test_patient_cannot_update_another_users_address(): void
    {
        $otherUser = User::factory()->patient()->create();
        $address = Address::factory()->for($otherUser)->create();

        $response = $this->putJson("/api/patient/addresses/{$address->id}", [
            'recipient_name' => 'Hacked Name',
            'address' => 'Hacked Address',
        ]);

        $response->assertStatus(403);
    }

    public function test_patient_cannot_delete_another_users_address(): void
    {
        $otherUser = User::factory()->patient()->create();
        $address = Address::factory()->for($otherUser)->create();

        $response = $this->deleteJson("/api/patient/addresses/{$address->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('addresses', ['id' => $address->id]);
    }
}