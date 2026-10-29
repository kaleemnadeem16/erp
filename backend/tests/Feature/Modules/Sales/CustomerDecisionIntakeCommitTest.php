<?php

namespace Tests\Feature\Modules\Sales;

use App\Models\User;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Models\CustomerDecisionCase;
use App\Modules\Sales\Models\CustomerDecisionEvidence;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CA0a — the real COMMIT and two real sessions. A test transaction never commits, so the guards are also proven on committed rows, from a second PostgreSQL connection, and the
 * database clock is shown to stamp the recording time at the moment of the write.
 */
class CustomerDecisionIntakeCommitTest extends TestCase
{
    use DatabaseMigrations;

    private int $userId;

    private int $partyId;

    public function runDatabaseMigrations(): void
    {
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();
        $this->beforeApplicationDestroyed(function (): void {
            // A schema downgrade deliberately refuses intake evidence; this isolated test database is disposable, so wipe and rebuild it.
            DB::purge('session_b');
            $this->artisan('migrate:fresh', $this->migrateFreshUsing());
            RefreshDatabaseState::$migrated = false;
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.session_b' => config('database.connections.testing')]);
        $this->userId = User::factory()->create()->id;
        $this->partyId = Party::create(['code' => 'CUSTOMER', 'name' => 'Customer Ltd'])->id;
    }

    private function sessionB(): Connection
    {
        return DB::connection('session_b');
    }

    public function test_committed_rows_are_guarded_from_a_second_session_and_the_database_clock_stamps_them(): void
    {
        $before = now()->subSeconds(2);
        $case = CustomerDecisionCase::create(['party_id' => $this->partyId, 'intake_identity' => 'PO-COMMIT', 'recorded_by' => $this->userId, 'recorded_at' => '2001-01-01 00:00:00+00']);
        $first = CustomerDecisionEvidence::create(['case_id' => $case->id, 'source_kind' => 'EMAIL', 'authority_verification_basis' => 'UNVERIFIED', 'occurrence_precision' => 'UNKNOWN',
            'factual_description' => 'An email.', 'observation_state' => 'OBSERVED', 'recorded_by' => $this->userId]);

        $this->assertTrue(DB::table('customer_decision_cases')->where('id', $case->id)->value('recorded_at') > $before->toDateTimeString(), 'the supplied 2001 time was replaced by the database clock');
        $b = $this->sessionB();
        $this->assertSame(1, (int) $b->table('customer_decision_evidence')->where('id', $first->id)->count(), 'the second session sees the committed row');

        foreach ([
            fn () => $b->table('customer_decision_evidence')->where('id', $first->id)->update(['factual_description' => 'rewritten']),
            fn () => $b->table('customer_decision_evidence')->where('id', $first->id)->delete(),
            fn () => $b->table('customer_decision_cases')->where('id', $case->id)->update(['intake_identity' => 'renamed']),
            fn () => $b->table('customer_decision_cases')->where('id', $case->id)->delete(),
            fn () => $b->statement('TRUNCATE customer_decision_evidence'),
            fn () => $b->statement('TRUNCATE customer_decision_cases CASCADE'),
        ] as $attack) {
            try {
                $attack();
                $this->fail('A committed row was altered from a second session.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('An email.', DB::table('customer_decision_evidence')->where('id', $first->id)->value('factual_description'));
    }

    public function test_two_sessions_cannot_both_extend_one_evidence_chain(): void
    {
        $case = CustomerDecisionCase::create(['party_id' => $this->partyId, 'intake_identity' => 'PO-CHAIN', 'recorded_by' => $this->userId]);
        $row = ['company_id' => 1, 'case_id' => $case->id, 'source_kind' => 'EMAIL', 'authority_verification_basis' => 'UNVERIFIED', 'occurrence_precision' => 'UNKNOWN',
            'factual_description' => 'An email.', 'observation_state' => 'OBSERVED', 'recorded_by' => $this->userId, 'recorded_at' => now()];
        $root = DB::table('customer_decision_evidence')->insertGetId($row);

        DB::table('customer_decision_evidence')->insert($row + ['predecessor_id' => $root]);
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('customer_decision_evidence_successor_unique');
        $this->sessionB()->table('customer_decision_evidence')->insert($row + ['predecessor_id' => $root]);
    }
}
