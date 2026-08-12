<?php

namespace Tests\Feature\Support;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * spatie/laravel-activitylog via App\Models\Concerns\LogsActivityWithDefaults.
 */
class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_user_logs_an_activity_entry(): void
    {
        $user = User::factory()->create();

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'event' => 'created',
        ]);
    }

    public function test_updating_a_user_logs_only_the_changed_attributes(): void
    {
        $user = User::factory()->create(['name' => 'Original Name']);

        $user->update(['name' => 'Updated Name']);

        $activity = $this->latestActivityFor($user, 'updated');

        $this->assertNotNull($activity);
        $this->assertSame('Updated Name', $activity->attribute_changes['attributes']['name']);
        $this->assertArrayNotHasKey('email', $activity->attribute_changes['attributes']);
    }

    public function test_password_is_never_recorded_in_the_activity_log(): void
    {
        $user = User::factory()->create(['name' => 'Original Name']);

        // password alone is excluded entirely (see the next test), so change name too —
        // otherwise there'd be nothing left to log and dontLogEmptyChanges() would
        // correctly suppress the entry, which isn't what this test is checking.
        $user->forceFill([
            'name' => 'Updated Name',
            'password' => 'a-new-plaintext-password',
        ])->save();

        $activity = $this->latestActivityFor($user, 'updated');

        $this->assertNotNull($activity);
        $this->assertSame('Updated Name', $activity->attribute_changes['attributes']['name']);
        $this->assertArrayNotHasKey('password', $activity->attribute_changes['attributes']);
    }

    public function test_a_change_to_only_an_excluded_attribute_logs_nothing(): void
    {
        $user = User::factory()->create();
        $countBefore = Activity::query()->where('subject_id', $user->id)->count();

        $user->forceFill(['password' => 'a-new-plaintext-password'])->save();

        $this->assertSame($countBefore, Activity::query()->where('subject_id', $user->id)->count());
    }

    public function test_a_no_op_save_does_not_create_a_log_entry(): void
    {
        $user = User::factory()->create();
        $countAfterCreate = Activity::query()->where('subject_id', $user->id)->count();

        $user->save(); // nothing changed

        $this->assertSame($countAfterCreate, Activity::query()->where('subject_id', $user->id)->count());
    }

    private function latestActivityFor(User $user, string $event): ?Activity
    {
        return Activity::query()
            ->where('subject_type', User::class)
            ->where('subject_id', $user->id)
            ->where('event', $event)
            ->latest('id')
            ->first();
    }
}
