<?php

namespace Tests\Feature\Web;

use App\Models\Pharmacy;
use App\Models\User;
use Tests\TestCase;

class RoleMiddlewareTest extends TestCase
{
    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_patient_is_redirected_to_login_from_admin_routes(): void
    {
        $patient = User::factory()->patient()->create();

        $this->actingAs($patient)->get('/')->assertRedirect(route('login.show'));
    }

    public function test_admin_can_access_admin_dashboard(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/')->assertOk();
    }

    public function test_pharmacy_is_redirected_to_pharmacy_dashboard_from_admin_routes(): void
    {
        $pharmacyUser = User::factory()->pharmacy()->create();

        $this->actingAs($pharmacyUser)->get('/')->assertRedirect(route('pharmacy.dashboard.index'));
    }

    public function test_pharmacy_can_access_pharmacy_dashboard(): void
    {
        $pharmacyUser = User::factory()->pharmacy()->create();
        Pharmacy::factory()->create(['user_id' => $pharmacyUser->id]);

        $this->actingAs($pharmacyUser)->get('/pharmacy/dashboard')->assertOk();
    }

    public function test_admin_cannot_access_pharmacy_dashboard(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/pharmacy/dashboard')->assertRedirect(route('dashboard'));
    }
}
