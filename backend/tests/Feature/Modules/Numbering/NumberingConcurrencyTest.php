<?php

namespace Tests\Feature\Modules\Numbering;

use App\Modules\Numbering\Actions\EnsureDefaultSeries;
use App\Modules\Numbering\Services\NumberingService;
use App\Modules\Numbering\Support\NumberingRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeNumberableDocument;
use Tests\TestCase;

/**
 * The two allocation strategies under real concurrency (ADR 0079 §9). Two genuinely separate database sessions ("testing" and
 * "testing_b") take turns inside open transactions — which a RefreshDatabase test, wrapped in one uncommitted transaction, could
 * never show — so this class commits its own data and cleans up after itself.
 */
class NumberingConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.testing_b' => config('database.connections.testing')]);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection('testing');
        foreach (['testing', 'testing_b'] as $name) {
            while (DB::connection($name)->transactionLevel() > 0) {
                DB::connection($name)->rollBack();
            }
        }
        $this->cleanup();
        DB::purge('testing_b');
        parent::tearDown();
    }

    private function cleanup(): void
    {
        DB::setDefaultConnection('testing');
        $db = DB::connection('testing');
        foreach ($db->table('numbering_counters')->whereNotNull('sequence_name')->pluck('sequence_name') as $name) {
            $db->statement('drop sequence if exists '.$name);
        }
        foreach (['numbering_allocations', 'numbering_counter_adjustments', 'numbering_counters', 'numbering_series_rules', 'numbering_series_versions'] as $table) {
            $db->table($table)->delete();
        }
        $db->table('numbering_series')->where('doc_type', 'SALES_INVOICE')->delete();
    }

    private function prepare(string $continuity): void
    {
        app(NumberingRegistry::class)->register(new FakeNumberableDocument);
        $series = app(EnsureDefaultSeries::class)(app(NumberingRegistry::class)->get('SALES_INVOICE'));
        DB::table('numbering_series_versions')->where('series_id', $series->id)->update(['continuity' => $continuity]);
    }

    private function in(string $connection, \Closure $work): mixed
    {
        DB::setDefaultConnection($connection);
        try {
            return $work();
        } finally {
            DB::setDefaultConnection('testing');
        }
    }

    private function allocate(string $at = '2026-10-02 10:00:00'): string
    {
        return app(NumberingService::class)->allocate(FakeNumberableDocument::context($at))->number;
    }

    public function test_gapless_serialises_two_sessions_and_hands_out_each_number_once(): void
    {
        $this->prepare('GAPLESS');
        $this->assertSame('0001', $this->allocate()); // creates and commits the counter

        // Session A takes 0002 and has not committed yet.
        DB::connection('testing')->beginTransaction();
        $this->assertSame('0002', $this->allocate());

        // Session B must wait for A; with a short lock timeout it proves it is blocked rather than racing ahead.
        $blocked = $this->in('testing_b', function (): bool {
            DB::connection('testing_b')->beginTransaction();
            DB::connection('testing_b')->statement("set local lock_timeout = '300ms'");
            try {
                $this->allocate();

                return false;
            } catch (QueryException $e) {
                return str_contains($e->getMessage(), 'lock timeout');
            } finally {
                DB::connection('testing_b')->rollBack();
            }
        });
        $this->assertTrue($blocked, 'a second session must wait for the first, not take a number beside it');

        DB::connection('testing')->commit();
        $this->assertSame('0003', $this->in('testing_b', fn () => $this->allocate()));
    }

    public function test_gapless_returns_the_number_when_the_first_session_rolls_back(): void
    {
        $this->prepare('GAPLESS');
        $this->assertSame('0001', $this->allocate());

        DB::connection('testing')->beginTransaction();
        $this->assertSame('0002', $this->allocate());
        DB::connection('testing')->rollBack();

        $this->assertSame('0002', $this->in('testing_b', fn () => $this->allocate()), 'no gap: the rolled-back number was given back');
    }

    public function test_allow_gaps_never_waits_for_another_session_and_a_rollback_leaves_a_gap(): void
    {
        $this->prepare('ALLOW_GAPS');
        $this->assertSame('0001', $this->allocate());

        // Session A takes 0002 and stays open.
        DB::connection('testing')->beginTransaction();
        $this->assertSame('0002', $this->allocate());

        // Session B is not blocked at all, even with a very short lock timeout, and takes the next number.
        $second = $this->in('testing_b', function (): string {
            DB::connection('testing_b')->beginTransaction();
            DB::connection('testing_b')->statement("set local lock_timeout = '300ms'");
            try {
                $number = $this->allocate();
                DB::connection('testing_b')->commit();

                return $number;
            } catch (\Throwable $e) {
                DB::connection('testing_b')->rollBack();
                throw $e;
            }
        });
        $this->assertSame('0003', $second);

        DB::connection('testing')->rollBack(); // 0002 is lost for good
        $this->assertSame('0004', $this->allocate(), 'allow gaps: 0002 was not given back, and nothing repeated');
    }

    public function test_two_sessions_creating_the_same_first_counter_end_up_with_one_counter(): void
    {
        $this->prepare('ALLOW_GAPS');

        // Session A creates the counter (and its sequence) in an open transaction and takes 0001.
        DB::connection('testing')->beginTransaction();
        $this->assertSame('0001', $this->allocate());

        // Session B arrives for the same, not-yet-committed counter: the unique index makes it wait, it does not make a second one.
        $waited = $this->in('testing_b', function (): bool {
            DB::connection('testing_b')->beginTransaction();
            DB::connection('testing_b')->statement("set local lock_timeout = '300ms'");
            try {
                $this->allocate();

                return false;
            } catch (QueryException $e) {
                return str_contains($e->getMessage(), 'lock timeout');
            } finally {
                DB::connection('testing_b')->rollBack();
            }
        });
        $this->assertTrue($waited);

        DB::connection('testing')->commit();
        $this->assertSame('0002', $this->in('testing_b', fn () => $this->allocate()));
        $this->assertSame(1, DB::table('numbering_counters')->count(), 'one counter, one sequence');
    }

    public function test_a_long_run_of_allocations_is_unique_and_in_order_for_both_policies(): void
    {
        foreach (['GAPLESS', 'ALLOW_GAPS'] as $policy) {
            $this->cleanup();
            $this->prepare($policy);
            $numbers = [];
            for ($i = 0; $i < 60; $i++) {
                // Alternate sessions so each policy is driven from two connections.
                $numbers[] = $i % 2 === 0 ? $this->allocate() : $this->in('testing_b', fn () => $this->allocate());
            }
            $this->assertSame($numbers, array_values(array_unique($numbers)), "{$policy}: no number repeated");
            $this->assertSame(array_map(fn (int $n): string => str_pad((string) $n, 4, '0', STR_PAD_LEFT), range(1, 60)), $numbers, "{$policy}: contiguous when nothing failed");
        }
    }
}
