<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CatalogImportRouteTest extends TestCase
{
    public function test_admin_can_trigger_catalog_import(): void
    {
        $admin = User::factory()->admin()->create();
        $admin->assignRole('admin');

        Artisan::shouldReceive('call')
            ->once()
            ->with('moh:import')
            ->andReturn(0);
        Artisan::shouldReceive('output')
            ->andReturn('');

        $response = $this->actingAs($admin)->post(route('settings.catalog.import'));

        $response->assertRedirect();
    }

    public function test_non_admin_cannot_access_catalog_import(): void
    {
        $user = User::factory()->pharmacy()->create();
        $user->assignRole('pharmacy');

        $this->actingAs($user)->post(route('settings.catalog.import'))
            ->assertRedirect();
    }
}