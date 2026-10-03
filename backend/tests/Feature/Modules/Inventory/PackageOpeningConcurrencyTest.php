<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Models\User;
use App\Modules\Inventory\Actions\PostPackageOpening;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\PackageState;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Committed fixture: a second connection must be able to see the locked location. */
class PackageOpeningConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();
        $this->beforeApplicationDestroyed(function (): void {
            // A schema downgrade deliberately refuses immutable opening history.
            // This isolated test database is disposable: wipe/rebuild it instead.
            $this->artisan('migrate:fresh', $this->migrateFreshUsing());
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_saved_opening_holds_location_lock_against_a_competing_connection(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();
        $actor = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        $parent = PackageOperation::query()->where('request_key', 'demo-package-opening-checkpoint')->sole();
        $state = PackageState::query()->where('operation_id', $parent->id)->sole();
        config(['database.connections.opening_location_probe' => config('database.connections.testing')]);
        // This row was committed before taking the lock; RefreshDatabase's uncommitted
        // fixtures would be invisible to the probe and could not prove exclusion.
        $this->assertCount(1, DB::connection('opening_location_probe')->select('SELECT id FROM locations WHERE id=?', [$parent->location_id]));
        DB::beginTransaction();
        try {
            app(PostPackageOpening::class)(['operation_id' => $parent->id, 'state_id' => $state->id,
                'count' => 1, 'kind' => 'OPEN', 'request_key' => 'concurrent-opening', 'reason' => 'Lock proof'], $actor);
            try {
                DB::connection('opening_location_probe')->select('SELECT id FROM locations WHERE id=? FOR UPDATE NOWAIT', [$parent->location_id]);
                $this->fail('The competing writer must wait for the location.');
            } catch (QueryException $error) {
                $this->assertSame('55P03', $error->errorInfo[0]);
            }
        } finally {
            DB::rollBack();
            DB::purge('opening_location_probe');
        }
        $this->assertFalse(PackageOperation::query()->where('request_key', 'concurrent-opening')->exists());
    }
}
