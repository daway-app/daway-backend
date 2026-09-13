<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تغطية فلاتر صفحة سجل الأنشطة (Admin /logs).
 *
 * قبل الإصلاح كانت قائمة "نوع النشاط" وحقل التاريخ بلا `name` وغير مقروءين في
 * LogController::index — أي أنهما كانا يبدوان فعّالين بينما لا يفلتران شيئًا.
 */
class AdminLogsFilterTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function seedActivities(): void
    {
        activity()->event('created')->log('created-row');
        activity()->event('updated')->log('updated-row');
        activity()->event('deleted')->log('deleted-row');
    }

    public function test_event_filter_narrows_results(): void
    {
        $this->seedActivities();

        $response = $this->actingAs($this->admin())->get(route('logs.index', ['event' => 'created']));

        $response->assertOk()
            ->assertSee('created-row')
            ->assertDontSee('updated-row')
            ->assertDontSee('deleted-row');
    }

    public function test_auth_filter_does_not_leak_model_events(): void
    {
        $this->seedActivities();

        $response = $this->actingAs($this->admin())->get(route('logs.index', ['event' => 'auth']));

        $response->assertOk()
            ->assertDontSee('created-row')
            ->assertDontSee('updated-row')
            ->assertDontSee('deleted-row');
    }

    public function test_invalid_event_value_is_ignored(): void
    {
        $this->seedActivities();

        $response = $this->actingAs($this->admin())->get(route('logs.index', ['event' => 'bogus']));

        $response->assertOk()
            ->assertSee('created-row')
            ->assertSee('updated-row')
            ->assertSee('deleted-row');
    }

    public function test_date_filter_narrows_results(): void
    {
        $this->seedActivities();

        $matching = $this->actingAs($this->admin())->get(
            route('logs.index', ['date' => now()->toDateString()])
        );
        $matching->assertOk()->assertSee('created-row');

        $empty = $this->actingAs($this->admin())->get(
            route('logs.index', ['date' => '2020-01-01'])
        );
        $empty->assertOk()->assertDontSee('created-row');
    }

    public function test_filters_render_with_names_and_preserve_state(): void
    {
        $this->seedActivities();

        $response = $this->actingAs($this->admin())->get(
            route('logs.index', ['event' => 'updated', 'date' => '2020-01-01'])
        );

        $response->assertOk()
            ->assertSee('name="event"', false)
            ->assertSee('name="date"', false)
            ->assertSee('value="2020-01-01"', false)
            ->assertSee('selected', false);
    }
}
