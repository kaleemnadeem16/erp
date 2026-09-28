<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Actions\DecideRecalculationApproval;
use App\Modules\Inventory\Actions\ExecuteRecalculation;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\RecalculationImpactVersion;
use App\Modules\Inventory\Models\RecalculationRequest;
use App\Modules\Inventory\Support\RecalculationApprovalDecision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RecalculationLifecycleDemoSeeder claims a request in every later state of the backdated-receipt
 * workflow and both shapes of correction. Each claim is checked against the stored facts — and a
 * state that means "cannot proceed" is checked by trying to proceed.
 */
final class RecalculationLifecycleDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('demo:seed')->assertSuccessful();
    }

    public function test_the_stock_seeders_own_request_is_still_approved_and_unexecuted(): void
    {
        $original = RecalculationRequest::query()->where('source_type', 'DEMO_RECALC_BACKDATED_RECEIPT')->sole();

        $this->assertSame(['APPROVED'], $this->decisions($original));
        $this->assertNull($this->execution($original));
    }

    public function test_an_avco_request_is_approved_and_executed_converging_the_pool(): void
    {
        $request = $this->request(11);
        $version = $request->impactVersions->sole();

        $this->assertSame(['APPROVED'], $this->decisions($request));
        $execution = $this->execution($request);
        $this->assertNotNull($execution);
        $this->assertSame($this->supervisor()->id, $execution->executed_by);

        // The pool now matches the exact counterfactual the approver saw, via one adjustment entry.
        $pool = DB::table('avco_pools')->where('item_id', $this->item('demo-recalc-titanium')->id)->sole();
        $this->assertSame($version->counterfactual_qty_on_hand, $pool->qty_on_hand);
        $this->assertSame($version->counterfactual_inventory_value, $pool->inventory_value);
        $adjustments = DB::table('stock_valuation_entries as e')
            ->join('stock_moves as m', 'm.id', '=', 'e.stock_move_id')
            ->where('m.item_id', $this->item('demo-recalc-titanium')->id)->where('e.pool_effect', 'VALUATION_ADJUSTMENT')->pluck('e.amount')->all();
        $this->assertSame(['-66.6670'], $adjustments);

        // The receipt was posted truthfully at its physical date, three weeks back — not today.
        $move = DB::table('stock_moves')->find($execution->stock_move_id);
        $this->assertSame(21, (int) Carbon::parse($move->moved_at)->startOfDay()->diffInDays(now()->startOfDay()));
    }

    public function test_a_fifo_request_is_executed_by_appending_reversal_and_replacement_entries(): void
    {
        $request = $this->request(12);
        $item = $this->item('demo-recalc-enamel');

        $this->assertNotNull($this->execution($request));

        $entries = DB::table('stock_layer_consumptions as c')
            ->join('stock_moves as m', 'm.id', '=', 'c.stock_move_id')
            ->where('m.item_id', $item->id)->orderBy('c.id')
            ->get(['c.id', 'c.purpose', 'c.qty_consumed', 'c.unit_cost', 'c.reverses_consumption_id', 'c.recalculation_request_id']);

        // The original issue drew 10 @100 and 2 @120. Nothing was rewritten: the 2 @120 draw is
        // reversed and replaced by 2 @80 from the layer that turned out to sit before it.
        $this->assertSame(
            [['ALLOCATION', '10.0000', '100.0000'], ['ALLOCATION', '2.0000', '120.0000'], ['ALLOCATION_REVERSAL', '2.0000', '120.0000'], ['ALLOCATION', '2.0000', '80.0000']],
            $entries->map(fn ($e): array => [$e->purpose, $e->qty_consumed, $e->unit_cost])->all(),
        );
        $this->assertSame($entries[1]->id, $entries[2]->reverses_consumption_id);
        $this->assertSame($request->id, $entries[3]->recalculation_request_id);
        $this->assertSame('13.0000', number_format((float) DB::table('stock_layers')->where('item_id', $item->id)->sum('qty_remaining'), 4, '.', ''));
    }

    public function test_a_rejected_request_cannot_be_executed(): void
    {
        $request = $this->request(13);

        $this->assertSame(['REJECTED'], $this->decisions($request));
        $this->assertNull($this->execution($request));

        $this->expectException(DomainInvariantException::class);
        app(ExecuteRecalculation::class)($request->impactVersions->sole(), $this->supervisor());
    }

    public function test_a_request_can_be_left_awaiting_its_first_decision(): void
    {
        $request = $this->request(14);

        $this->assertSame([], $this->decisions($request));
        $this->assertNull($this->execution($request));
    }

    public function test_requests_come_from_one_person_and_are_decided_by_another(): void
    {
        $ops = User::query()->where('email', 'demo-ops@erp.x1techs.com')->firstOrFail();

        foreach ([11, 12, 13, 14] as $sourceId) {
            $request = $this->request($sourceId);
            $this->assertSame($ops->id, $request->requested_by);
            $deciders = DB::table('recalculation_approvals')->where('impact_version_id', $request->impactVersions->sole()->id)->pluck('decided_by')->all();
            foreach ($deciders as $decider) {
                $this->assertSame($this->supervisor()->id, $decider);
            }
        }
    }

    public function test_the_undecided_request_can_be_decided_and_executed_by_hand_and_a_rerun_still_succeeds(): void
    {
        $request = $this->request(14);
        $version = $request->impactVersions->sole();

        // What the demo login does in the UI.
        app(DecideRecalculationApproval::class)($version, RecalculationApprovalDecision::Approved, 'Checked against the invoice.', $this->supervisor());
        app(ExecuteRecalculation::class)($version->fresh(), $this->supervisor());
        $this->assertNotNull($this->execution($request));

        $movesBefore = DB::table('stock_moves')->count();
        $requestsBefore = RecalculationRequest::query()->count();

        // Recognised by natural key, never re-requested against the drifted live stock.
        $this->artisan('demo:seed')->assertSuccessful();

        $this->assertSame($movesBefore, DB::table('stock_moves')->count());
        $this->assertSame($requestsBefore, RecalculationRequest::query()->count());
    }

    public function test_a_plain_rerun_changes_nothing(): void
    {
        $counts = fn (): array => array_map(
            fn (string $table): int => DB::table($table)->count(),
            ['recalculation_requests', 'recalculation_impact_versions', 'recalculation_approvals', 'recalculation_executions',
                'stock_moves', 'stock_layers', 'stock_layer_consumptions', 'stock_valuation_entries', 'items'],
        );
        $before = $counts();
        $this->assertSame([5, 5, 4, 2], array_slice($before, 0, 4));

        $this->artisan('demo:seed')->assertSuccessful();

        $this->assertSame($before, $counts());
    }

    public function test_a_late_manual_receipt_does_not_break_a_rerun(): void
    {
        $item = $this->item('demo-recalc-titanium');
        $location = DB::table('locations')->where('code', 'stock')->first();
        app(PostInventoryMovement::class)([
            'item_id' => $item->id, 'location_id' => $location->id, 'qty' => '5.0000', 'uom_id' => $item->stock_uom_id,
            'moved_at' => now(), 'source_type' => 'MANUAL_RECEIPT', 'source_id' => 1,
            'unit_cost' => '110.0000', 'currency_id' => DB::table('currencies')->where('code', 'PKR')->value('id'),
            'fx_rate' => '1.000000000', 'base_unit_cost' => '110.0000',
        ]);

        $this->artisan('demo:seed')->assertSuccessful();

        $this->assertSame(5, RecalculationRequest::query()->count());
    }

    private function request(int $sourceId): RecalculationRequest
    {
        return RecalculationRequest::query()->where('source_type', 'DEMO_RECALC_LIFECYCLE')->where('source_id', $sourceId)->with('impactVersions')->sole();
    }

    /** @return list<string> */
    private function decisions(RecalculationRequest $request): array
    {
        return DB::table('recalculation_approvals')
            ->whereIn('impact_version_id', RecalculationImpactVersion::query()->where('recalculation_request_id', $request->id)->select('id'))
            ->orderBy('id')->pluck('decision')->all();
    }

    private function execution(RecalculationRequest $request): ?object
    {
        return DB::table('recalculation_executions')->where('recalculation_request_id', $request->id)->first();
    }

    private function supervisor(): User
    {
        return User::query()->where('email', 'demo-supervisor@erp.x1techs.com')->firstOrFail();
    }

    private function item(string $code): Item
    {
        return Item::query()->where('code', $code)->firstOrFail();
    }
}
