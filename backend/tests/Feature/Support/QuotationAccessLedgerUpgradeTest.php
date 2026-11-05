<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Modules\Sales\Actions\CaptureQuotationAccessBaseline;
use App\Modules\Sales\Actions\ChangeQuotationAccessUnit;
use App\Modules\Sales\Actions\ReassignQuotationOwner;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Modules\Sales\Concerns\BuildsQuotationOwnership;
use Tests\Support\AssertsPopulatedRollbackRefusal;
use Tests\Support\BuildsQuotationShareStorage;
use Tests\TestCase;

class QuotationAccessLedgerUpgradeTest extends TestCase
{
    use AssertsPopulatedRollbackRefusal, BuildsQuotationOwnership, BuildsQuotationShareStorage;
    use DatabaseMigrations {
        migrateFreshUsing as private fullSchemaOptions;
    }

    protected function migrateFreshUsing(): array
    {
        $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn (string $path): bool => ! str_contains($path, '2026_11_10_010000_') && ! str_contains($path, '2026_11_10_020000_')));

        return $this->fullSchemaOptions() + ['--path' => array_map(fn (string $path): string => str_replace(base_path().'/', '', $path), $paths)];
    }

    public function runDatabaseMigrations(): void
    {
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();
        $this->beforeApplicationDestroyed(function (): void {
            // Restore the full schema on this private disposable database; never disable evidence guards.
            $this->artisan('migrate:fresh', $this->fullSchemaOptions());
            RefreshDatabaseState::$migrated = false;
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertFalse(Schema::hasTable('quotation_access_events'));
        $this->setUpOwnership();
        $this->activateOwnershipDuties();
        $this->assertSame(0, DB::table('users')->where('authorization_generation', '<>', 0)->count());
    }

    private function applyNewMigrations(): void
    {
        DB::transaction(function (): void {
            (require database_path('migrations/2026_11_10_010000_advance_quotation_actor_authorization_epochs.php'))->up();
            (require database_path('migrations/2026_11_10_020000_create_quotation_access_events_and_shares.php'))->up();
        });
    }

    public function test_populated_upgrade_preserves_all_old_history_and_projections_exactly(): void
    {
        $family = $this->nativeFamily();
        app(ChangeQuotationAccessUnit::class)($this->assigner, $family->id, $this->unit(), 1, 0, $this->key(), 'Historical unit fixture');
        app(ReassignQuotationOwner::class)($this->assigner, $family->id, $this->colleague->id, 2, 1, $this->key(), 'Historical handover fixture');
        app(ChangeQuotationAccessUnit::class)($this->assigner, $family->id, $this->unit(), 3, 1, $this->key(), 'Historical second unit fixture');
        $legacy = $this->legacyFamily();
        app(CaptureQuotationAccessBaseline::class)($this->assigner, $legacy->id, $this->colleague->id, null, 'private-legacy-map', 'private-owner-approval', $this->key(), 'Historical capture fixture');
        $history = DB::table('quotation_ownership_events')->orderBy('id')->get()->toJson();
        $projection = DB::table('quotation_families')->orderBy('id')->get()->toJson();
        $before = DB::selectOne('SELECT clock_timestamp() AS instant')->instant;
        $this->applyNewMigrations();
        $this->assertSame($history, DB::table('quotation_ownership_events')->orderBy('id')->get()->toJson());
        $this->assertSame($projection, DB::table('quotation_families')->orderBy('id')->get()->toJson());
        $this->assertSame(3, DB::table('quotation_access_events')->count());
        $this->assertSame(0, DB::table('quotation_access_events')->where('origin', '<>', 'BACKFILL')->count());
        $this->assertSame(0, DB::table('quotation_access_events')->where('recorded_at', '<', $before)->count(), 'New ledger recording times never pretend to predate the upgrade.');
        $sourceIds = DB::table('quotation_ownership_events')->whereIn('kind', ['UNIT_CHANGED', 'CAPTURED_BASELINE'])->orderBy('id')->pluck('id')->all();
        $this->assertEqualsCanonicalizing($sourceIds, DB::table('quotation_access_events')->pluck('ownership_event_id')->all());
        app(ChangeQuotationAccessUnit::class)($this->assigner, $family->id, null, 4, 2, $this->key(), 'New unit change after upgrade');
        $this->assertSame(3, $family->fresh()->access_generation);
        $this->assertSame('LIVE', DB::table('quotation_access_events')->where('family_id', $family->id)->orderByDesc('generation')->value('origin'));
        $this->assertPopulatedRollbackRefused('2026_11_10_020000_create_quotation_access_events_and_shares.php', ['quotation_access_events', 'quotation_ownership_events']);
    }

    public function test_share_pins_at_zero_still_prevent_disabling_actor_epoch_invalidation(): void
    {
        $family = $this->nativeFamily();
        $this->applyNewMigrations();
        $this->rawGrant($family, $this->preparer, $this->colleague);
        $this->assertSame(0, DB::table('users')->where('authorization_generation', '<>', 0)->count());
        $this->assertSame(0, (int) DB::table('quotation_access_grants')->value('issuer_authorization_generation'));
        $this->assertPopulatedRollbackRefused('2026_11_10_010000_advance_quotation_actor_authorization_epochs.php', ['users', 'quotation_access_grants', 'quotation_access_events']);
        $this->assertSame(1, $family->fresh()->access_generation);
    }
}
