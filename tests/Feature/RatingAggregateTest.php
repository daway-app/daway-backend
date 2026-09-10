<?php

namespace Tests\Feature;

use App\Models\Pharmacy;
use App\Models\Rating;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H-6: avg_rating aggregate maintenance — previously a dead column
 * served to the mobile contract as 0.00 forever.
 */
class RatingAggregateTest extends TestCase
{
    use RefreshDatabase;

    public function test_avg_rating_updates_when_a_rating_is_created(): void
    {
        $pharmacy = Pharmacy::factory()->create(['avg_rating' => 0]);
        $patient = User::factory()->patient()->create();

        Rating::factory()->create([
            'user_id' => $patient->id,
            'pharmacy_id' => $pharmacy->id,
            'stars_rating' => 5,
        ]);

        $pharmacy->refresh();
        $this->assertSame(5.0, (float) $pharmacy->avg_rating);
    }

    public function test_avg_rating_is_the_true_average_across_raters(): void
    {
        $pharmacy = Pharmacy::factory()->create(['avg_rating' => 0]);

        Rating::factory()->create([
            'user_id' => User::factory()->patient()->create()->id,
            'pharmacy_id' => $pharmacy->id,
            'stars_rating' => 3,
        ]);
        Rating::factory()->create([
            'user_id' => User::factory()->patient()->create()->id,
            'pharmacy_id' => $pharmacy->id,
            'stars_rating' => 5,
        ]);

        $pharmacy->refresh();
        $this->assertSame(4.0, (float) $pharmacy->avg_rating);
    }

    public function test_ratings_rebuild_command_recomputes_all_pharmacies(): void
    {
        $pharmacy = Pharmacy::factory()->create(['avg_rating' => 0]);

        Rating::factory()->create([
            'user_id' => User::factory()->patient()->create()->id,
            'pharmacy_id' => $pharmacy->id,
            'stars_rating' => 4,
        ]);

        // انحراف صناعي — الأمر يعيد الحساب من المصدر
        $pharmacy->forceFill(['avg_rating' => 1.23])->save();

        $this->artisan('ratings:rebuild')->assertSuccessful();

        $pharmacy->refresh();
        $this->assertSame(4.0, (float) $pharmacy->avg_rating);
    }
}
