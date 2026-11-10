<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Demo seeders are create-only AND re-runnable on any later day: dates derived from "now" must never make a rerun collide with, or add to, what an earlier day seeded
 * (keyed quotation operations replay their recorded result instead of resubmitting a payload whose dates moved).
 */
final class DemoSeederLaterDayRerunTest extends TestCase
{
    use RefreshDatabase;

    /** Time-boxed live demo facts that a rerun renews once they lapse (RecalculationLifecycleDemoSeeder, ApprovalsDemoSeeder); everything else must stay exactly as it was. */
    private const RENEWABLE = ['recalculation_approvals' => true, 'approval_delegations' => true];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_rerun_on_a_later_day_adds_nothing_and_does_not_throw(): void
    {
        // Local preview, so the quotation demos really run. 20:30 UTC is already the next calendar day in Karachi: the UTC date and the company's date differ.
        config(['sales.dev_preview.quotation_duties' => true]);
        $this->app->detectEnvironment(fn (): string => 'local');
        $this->app->forgetInstance(SalesDutyManifest::class);
        Carbon::setTestNow(Carbon::parse('2026-11-10 20:30:00', 'UTC'));

        $this->artisan('demo:seed')->assertSuccessful();
        $this->assertGreaterThan(0, DB::table('sales_quotation_operation_receipts')->count(), 'the quotation demos must have run');
        $this->assertGreaterThan(0, QuotationRevision::query()->count());

        $previous = Carbon::now();
        foreach ([Carbon::parse('2026-11-11 20:30:00', 'UTC'), Carbon::parse('2026-12-20 04:10:00', 'UTC')] as $later) {
            $this->moveApprovalClock((int) $previous->diffInSeconds($later) + 7 * 86400);
            $previous = $later;
            $before = $this->snapshot();
            $renewable = $this->renewable();
            Carbon::setTestNow($later);
            $this->app->forgetInstance(SalesDutyManifest::class);
            $this->artisan('demo:seed')->assertSuccessful();
            $this->assertSame($before, $this->snapshot(), 'rerun on '.$later->toDateTimeString());
            // The documented exceptions (each has its own test): a time-boxed live fact that lapsed is renewed so the demo step stays live.
            foreach ($renewable as $table => $count) {
                $this->assertGreaterThanOrEqual($count, DB::table($table)->count(), $table);
            }
        }
    }

    /**
     * The database trigger that guards approvals reads the database clock, which the test clock does not move. Approval rows are immutable, so (as RecalculationLifecycleDemoSeederTest
     * does) their stored times are moved back by the elapsed interval, and by a further margin so they are also past the database clock's real "now" (the test dates are in the future).
     */
    private function moveApprovalClock(int $seconds): void
    {
        DB::statement('ALTER TABLE recalculation_approvals DISABLE TRIGGER USER');
        DB::table('recalculation_approvals')->update([
            'decided_at' => DB::raw("decided_at - interval '{$seconds} seconds'"),
            'expires_at' => DB::raw("expires_at - interval '{$seconds} seconds'"),
        ]);
        DB::statement('ALTER TABLE recalculation_approvals ENABLE TRIGGER USER');
    }

    /** @return array<string, int> */
    private function renewable(): array
    {
        return array_map(fn (string $table): int => DB::table($table)->count(), array_combine(array_keys(self::RENEWABLE), array_keys(self::RENEWABLE)));
    }

    /** @return array<string, int> */
    private function snapshot(): array
    {
        $counts = [];
        foreach (Schema::getTableListing() as $table) {
            $name = preg_replace('/^public\./', '', $table);
            if (in_array($name, ['migrations', 'cache', 'cache_locks', 'sessions', 'jobs', ...array_keys(self::RENEWABLE)], true)) {
                continue;
            }
            $counts[$name] = DB::table($name)->count();
        }
        ksort($counts);

        return $counts;
    }
}
