<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Exceptions\DomainInvariantException;
use App\Models\Currency;
use App\Models\User;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\NegativeStockOverrideRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * NegativeStockLifecycleDemoSeeder claims a record in every later stage of the negative-stock
 * workflow. Each claim is checked against the stored facts (and, where a state means "cannot be
 * used", by trying to use it) rather than by trusting the seeder's own comments.
 */
final class NegativeStockLifecycleDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('demo:seed')->assertSuccessful();
    }

    public function test_the_two_pending_requests_from_the_stock_seeder_are_left_undecided(): void
    {
        $pending = NegativeStockOverrideRequest::query()->where('source_type', 'DEMO_JOB_ISSUE')->get();

        $this->assertCount(2, $pending);
        foreach ($pending as $request) {
            $this->assertNull($request->approval, "request {$request->id} must still await its decision");
            $this->assertNull($request->review, "request {$request->id} must still await its review");
            $this->assertNull($request->shortage, "request {$request->id} must not have been posted");
        }
    }

    public function test_a_prior_approval_request_is_rejected_and_another_approved_and_posted(): void
    {
        [$rejected, $approved] = $this->requestsFor('demo-neg-primer');

        $this->assertSame('REJECTED', $rejected->approval->decision->value);
        $this->assertNull($rejected->shortage);
        $this->assertSame('APPROVED', $approved->approval->decision->value);
        // Posted: 20 kg on hand, 30 kg issued → 10 kg short, costed at the last valid average (400).
        $this->assertSame('OPEN', $approved->shortage->status);
        $this->assertSame('10.0000', $approved->shortage->qty_open);
        $this->assertSame('400.0000', $approved->shortage->provisional_unit_cost);
        $this->assertNull($approved->shortage->settled_at);
    }

    public function test_a_rejected_request_cannot_be_used_to_post_an_issue(): void
    {
        [$rejected] = $this->requestsFor('demo-neg-primer');
        $item = $this->item('demo-neg-primer');

        $this->expectException(DomainInvariantException::class);
        app(PostInventoryMovement::class)->issue([
            'item_id' => $item->id, 'location_id' => $this->location()->id, 'qty' => '5.0000', 'uom_id' => $item->stock_uom_id,
            'moved_at' => now(), 'source_type' => 'DEMO_NEG_STOCK', 'source_id' => $rejected->source_id,
            'override_request_id' => $rejected->id,
        ]);
    }

    public function test_decisions_and_reviews_are_made_by_someone_other_than_the_requester(): void
    {
        $ops = User::query()->where('email', 'demo-ops@erp.x1techs.com')->firstOrFail();
        $supervisor = User::query()->where('email', 'demo-supervisor@erp.x1techs.com')->firstOrFail();

        foreach (['demo-neg-primer', 'demo-neg-hardener', 'demo-neg-sealant'] as $code) {
            foreach ($this->requestsFor($code) as $request) {
                $this->assertSame($ops->id, $request->requested_by);
                if ($request->approval !== null) {
                    $this->assertSame($supervisor->id, $request->approval->decided_by);
                }
                if ($request->review !== null) {
                    $this->assertSame($supervisor->id, $request->review->reviewed_by);
                }
            }
        }
    }

    public function test_immediate_overrides_carry_both_review_outcomes(): void
    {
        [$hardener] = $this->requestsFor('demo-neg-hardener');
        [$sealant] = $this->requestsFor('demo-neg-sealant');

        $this->assertSame('IMMEDIATE_WITH_REVIEW', $hardener->authorisation_mode->value);
        $this->assertSame('JUSTIFIED', $hardener->review->outcome->value);
        $this->assertSame('FLAGGED', $sealant->review->outcome->value);
        $this->assertNull($hardener->approval);
    }

    public function test_the_settlement_states_are_open_partially_settled_and_settled(): void
    {
        [, $primer] = $this->requestsFor('demo-neg-primer');
        [$hardener] = $this->requestsFor('demo-neg-hardener');
        [$sealant] = $this->requestsFor('demo-neg-sealant');

        $this->assertSame(['OPEN', '10.0000'], [$primer->shortage->status, $primer->shortage->qty_open]);
        $this->assertSame(['PARTIALLY_SETTLED', '5.0000'], [$hardener->shortage->status, $hardener->shortage->qty_open]);
        $this->assertSame(['SETTLED', '0.0000'], [$sealant->shortage->status, $sealant->shortage->qty_open]);
        $this->assertNull($hardener->shortage->settled_at);
        $this->assertNotNull($sealant->shortage->settled_at);
    }

    public function test_settlements_carry_the_valuation_adjustment_and_only_excess_inbound_becomes_stock(): void
    {
        [$hardener] = $this->requestsFor('demo-neg-hardener');
        [$sealant] = $this->requestsFor('demo-neg-sealant');

        // 10 kg inbound at 275 against the 250 provisional cost, all consumed settling the shortage.
        $partial = DB::table('negative_stock_settlements')->where('shortage_id', $hardener->shortage->id)->sole();
        $this->assertSame(['10.0000', '250.0000', '275.0000', '25.0000', '-250.0000'], [
            $partial->qty_settled, $partial->provisional_unit_cost, $partial->actual_unit_cost, $partial->unit_cost_difference, $partial->valuation_adjustment,
        ]);
        $this->assertSame('0.0000', $this->onHand('demo-neg-hardener'));

        // 6 kg inbound against a 4 kg shortage: 4 settled, the remaining 2 kg is ordinary stock.
        $full = DB::table('negative_stock_settlements')->where('shortage_id', $sealant->shortage->id)->sole();
        $this->assertSame(['4.0000', '-40.0000'], [$full->qty_settled, $full->valuation_adjustment]);
        $this->assertSame('2.0000', $this->onHand('demo-neg-sealant'));
    }

    public function test_the_open_shortage_can_be_settled_by_hand_and_a_rerun_still_succeeds(): void
    {
        [, $primer] = $this->requestsFor('demo-neg-primer');
        $item = $this->item('demo-neg-primer');

        // What the demo login does in the UI: receive more primer at the shortage's own location.
        app(PostInventoryMovement::class)([
            'item_id' => $item->id, 'location_id' => $this->location()->id, 'qty' => '12.0000', 'uom_id' => $item->stock_uom_id,
            'moved_at' => now(), 'source_type' => 'MANUAL_RECEIPT', 'source_id' => 1,
            'unit_cost' => '420.0000', 'currency_id' => Currency::query()->where('code', 'PKR')->firstOrFail()->id,
            'fx_rate' => '1.000000000', 'base_unit_cost' => '420.0000',
        ]);
        $this->assertSame('SETTLED', $primer->shortage()->firstOrFail()->status);
        $this->assertSame('2.0000', $this->onHand('demo-neg-primer'));

        $movesBefore = DB::table('stock_moves')->count();
        $requestsBefore = NegativeStockOverrideRequest::query()->count();

        // The scenario is recognised by its natural key, never recomputed from the drifted live stock.
        $this->artisan('demo:seed')->assertSuccessful();

        $this->assertSame($movesBefore, DB::table('stock_moves')->count());
        $this->assertSame($requestsBefore, NegativeStockOverrideRequest::query()->count());
        $this->assertSame('SETTLED', $primer->shortage()->firstOrFail()->status);
    }

    public function test_a_plain_rerun_changes_nothing_and_leaves_the_resting_policy(): void
    {
        $counts = fn (): array => array_map(
            fn (string $table): int => DB::table($table)->count(),
            ['negative_stock_override_requests', 'negative_stock_override_approvals', 'negative_stock_override_reviews',
                'negative_stock_shortages', 'negative_stock_settlements', 'stock_moves', 'stock_layers', 'items'],
        );
        $before = $counts();
        $this->assertSame([6, 2, 2, 3, 2], array_slice($before, 0, 5));

        $this->artisan('demo:seed')->assertSuccessful();

        $this->assertSame($before, $counts());
        $company = DB::table('companies')->where('id', 1)->first();
        $this->assertSame('AUTHORISED_OVERRIDE', $company->negative_stock_mode);
        $this->assertSame('IMMEDIATE_WITH_REVIEW', $company->negative_stock_authorisation_mode);
    }

    /** @return list<NegativeStockOverrideRequest> the item's override requests, oldest first */
    private function requestsFor(string $itemCode): array
    {
        return NegativeStockOverrideRequest::query()
            ->where('item_id', $this->item($itemCode)->id)
            ->with(['approval', 'review', 'shortage'])
            ->orderBy('id')
            ->get()
            ->all();
    }

    private function item(string $code): Item
    {
        return Item::query()->where('code', $code)->firstOrFail();
    }

    private function location(): Location
    {
        return Location::query()->whereHas('warehouse', fn ($query) => $query->where('code', 'demo-main'))->where('code', 'stock')->firstOrFail();
    }

    private function onHand(string $itemCode): string
    {
        return number_format((float) DB::table('stock_layers')->where('item_id', $this->item($itemCode)->id)->sum('qty_remaining'), 4, '.', '');
    }
}
