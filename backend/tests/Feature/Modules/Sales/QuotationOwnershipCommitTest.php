<?php

namespace Tests\Feature\Modules\Sales;

use App\Exceptions\DomainInvariantException;
use App\Modules\Sales\Actions\CaptureQuotationAccessBaseline;
use App\Modules\Sales\Actions\ChangeQuotationAccessUnit;
use App\Modules\Sales\Actions\ReassignQuotationOwner;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Modules\Sales\Concerns\BuildsQuotationOwnership;
use Tests\TestCase;

/**
 * QA1a — the real COMMIT and two real sessions. A test transaction never commits, so the deferred family/event agreement and the family lock are proven here with
 * committed fixtures and separate PostgreSQL connections.
 */
class QuotationOwnershipCommitTest extends TestCase
{
    use BuildsQuotationOwnership;
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();
        $this->beforeApplicationDestroyed(function (): void {
            // A schema downgrade deliberately refuses ownership evidence; this isolated test database is disposable, so wipe and rebuild it.
            DB::purge('owner_a');
            DB::purge('owner_b');
            $this->artisan('migrate:fresh', $this->migrateFreshUsing());
            RefreshDatabaseState::$migrated = false;
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.owner_a' => config('database.connections.testing'), 'database.connections.owner_b' => config('database.connections.testing')]);
        $this->setUpOwnership();
        $this->activateOwnershipDuties();
    }

    public function test_every_action_commits_for_real_with_the_family_agreeing_with_its_chain(): void
    {
        $family = $this->nativeFamily();
        $unit = $this->unit();
        app(ReassignQuotationOwner::class)($this->assigner, $family->id, $this->colleague->id, 1, 0, $this->key(), 'Handover');
        app(ChangeQuotationAccessUnit::class)($this->assigner, $family->id, $unit, 2, 0, $this->key(), 'Site move');
        $legacy = $this->legacyFamily();
        app(CaptureQuotationAccessBaseline::class)($this->assigner, $legacy->id, $this->colleague->id, null, 'sheet', 'A-1', $this->key(), 'Adopt');

        $row = DB::table('quotation_families')->find($family->id);
        $this->assertSame([$this->colleague->id, $unit, 3, 1, 'NATIVE'], [$row->responsible_owner_id, $row->access_unit_id, $row->ownership_generation, $row->access_generation, $row->access_baseline_state]);
        $this->assertSame(['INITIAL', 'REASSIGNED', 'UNIT_CHANGED'], DB::table('quotation_ownership_events')->where('family_id', $family->id)->orderBy('generation')->pluck('kind')->all());
        $this->assertSame('CAPTURED', DB::table('quotation_families')->where('id', $legacy->id)->value('access_baseline_state'));
    }

    public function test_commit_refuses_an_event_without_its_family_change_and_a_family_change_without_its_event(): void
    {
        $family = $this->nativeFamily();
        $event = ['company_id' => 1, 'family_id' => $family->id, 'generation' => 2, 'previous_event_id' => DB::table('quotation_ownership_events')->where('family_id', $family->id)->value('id'), 'kind' => 'REASSIGNED',
            'old_owner_id' => $this->preparer->id, 'new_owner_id' => $this->colleague->id, 'access_generation' => 0, 'actor_id' => $this->assigner->id, 'reason' => 'x', 'recorded_at' => now()];

        foreach (['event only' => fn (string $c) => DB::connection($c)->table('quotation_ownership_events')->insert($event),
            'family only' => fn (string $c) => DB::connection($c)->table('quotation_families')->where('id', $family->id)->update(['responsible_owner_id' => $this->colleague->id, 'ownership_generation' => 2])] as $label => $write) {
            DB::connection('owner_a')->statement('BEGIN');
            $refused = false;
            try {
                $write('owner_a');
                DB::connection('owner_a')->statement('COMMIT');
            } catch (QueryException $e) {
                $refused = true;
                $this->assertSame('23514', $e->errorInfo[0], $label.': '.$e->getMessage());
                DB::connection('owner_a')->statement('ROLLBACK');
            }
            $this->assertTrue($refused, "COMMIT must refuse: {$label}");
            $this->assertSame([1, $this->preparer->id], [DB::table('quotation_families')->where('id', $family->id)->value('ownership_generation'), DB::table('quotation_families')->where('id', $family->id)->value('responsible_owner_id')]);
            $this->assertSame(1, DB::table('quotation_ownership_events')->where('family_id', $family->id)->count());
        }
    }

    public function test_a_competing_session_waits_on_the_family_and_cannot_fork_the_chain(): void
    {
        $family = $this->nativeFamily();
        // Session A holds the family row mid-change.
        DB::connection('owner_a')->statement('BEGIN');
        DB::connection('owner_a')->select('SELECT id FROM quotation_families WHERE id = ? FOR UPDATE', [$family->id]);

        DB::connection('owner_b')->statement('BEGIN');
        DB::connection('owner_b')->statement("SET LOCAL lock_timeout = '300ms'");
        try {
            DB::connection('owner_b')->select('SELECT id FROM quotation_families WHERE id = ? FOR UPDATE', [$family->id]);
            $this->fail('A competing writer must wait for the family lock.');
        } catch (QueryException $e) {
            $this->assertSame('55P03', $e->errorInfo[0]);
        }
        DB::connection('owner_b')->statement('ROLLBACK');
        DB::connection('owner_a')->statement('ROLLBACK');

        // With the lock free, two requests from the same generation: exactly one wins, the other is stale.
        app(ReassignQuotationOwner::class)($this->assigner, $family->id, $this->colleague->id, 1, 0, $this->key(), 'First');
        try {
            app(ReassignQuotationOwner::class)($this->assigner, $family->id, $this->assigner->id, 1, 0, $this->key(), 'Second');
            $this->fail('The second request saw a stale generation.');
        } catch (DomainInvariantException $e) {
            $this->assertSame('QUOTATION_OWNERSHIP_STALE', $e->reasonCode);
        }
        $this->assertSame(2, DB::table('quotation_ownership_events')->where('family_id', $family->id)->count());
    }
}
