<?php

namespace Tests\Feature\Modules\Inventory;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Actions\CreateWarehouse;
use App\Modules\Inventory\Actions\DecideNegativeStockOverride;
use App\Modules\Inventory\Actions\RequestNegativeStockOverride;
use App\Modules\Inventory\Actions\ReviewNegativeStockOverride;
use App\Modules\Inventory\Support\NegativeStockApprovalDecision;
use App\Modules\Inventory\Support\NegativeStockReviewOutcome;
use App\Modules\Inventory\Support\ValuationMethod;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Modules\Purchasing\PurchaseOrderFoundationTest;

class NegativeStockOverrideWorkflowTest extends PurchaseOrderFoundationTest
{
    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        foreach (['inventory.negative-stock.request', 'inventory.negative-stock.override', 'inventory.negative-stock.approve', 'inventory.negative-stock.review'] as $permission) {
            Permission::findOrCreate($permission);
        }
    }

    public function test_prior_request_snapshots_shortage_and_requires_independent_approval(): void
    {
        [$item, $locationId] = $this->foundation('PRIOR_APPROVAL');
        $requester = User::factory()->create();
        $requester->givePermissionTo('inventory.negative-stock.request');
        $request = app(RequestNegativeStockOverride::class)($this->input($item->id, $locationId), $requester);

        // Nothing is on hand at this location, so the derived availability is
        // zero and the whole requested quantity is short.
        $this->assertSame('0.0000', $request->available_qty);
        $this->assertSame('5.0000', $request->shortage_qty);
        $this->assertSame('PRIOR_APPROVAL', $request->authorisation_mode->value);
        $this->assertNull($request->review_due_at);

        $requester->givePermissionTo('inventory.negative-stock.approve');
        try {
            app(DecideNegativeStockOverride::class)($request, NegativeStockApprovalDecision::Approved, 'self', $requester);
            $this->fail('Self approval must fail.');
        } catch (DomainInvariantException) {
        }
        $approver = User::factory()->create();
        $approver->givePermissionTo('inventory.negative-stock.approve');
        $approval = app(DecideNegativeStockOverride::class)($request, NegativeStockApprovalDecision::Approved, 'Evidence checked', $approver);
        $this->assertSame($approver->id, $approval->decided_by);
    }

    public function test_immediate_request_snapshots_deadline_and_requires_independent_review_by_default(): void
    {
        [$item, $locationId] = $this->foundation('IMMEDIATE_WITH_REVIEW');
        $requester = User::factory()->create();
        $requester->givePermissionTo(['inventory.negative-stock.override', 'inventory.negative-stock.review']);
        $request = app(RequestNegativeStockOverride::class)($this->input($item->id, $locationId, 2), $requester);
        $this->assertLessThanOrEqual(1, abs($request->review_due_at->getTimestamp() - now()->addHours(24)->getTimestamp()));
        $this->assertFalse($request->allow_self_review);
        Company::query()->findOrFail($request->company_id)->update(['negative_stock_allow_self_review' => true]);

        try {
            app(ReviewNegativeStockOverride::class)($request, NegativeStockReviewOutcome::Justified, 'self', $requester);
            $this->fail('Self review must fail.');
        } catch (DomainInvariantException) {
        }
        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo('inventory.negative-stock.review');
        $review = app(ReviewNegativeStockOverride::class)($request, NegativeStockReviewOutcome::Flagged, 'Follow up required', $reviewer);
        $this->assertSame('FLAGGED', $review->outcome->value);
    }

    public function test_disabled_movement_class_and_block_mode_are_rejected(): void
    {
        [$item, $locationId] = $this->foundation('PRIOR_APPROVAL');
        $actor = User::factory()->create();
        $actor->givePermissionTo('inventory.negative-stock.request');
        $input = $this->input($item->id, $locationId);
        $input['movement_class'] = 'SUPPLIER_RETURN';
        try {
            app(RequestNegativeStockOverride::class)($input, $actor);
            $this->fail('Disabled class must fail.');
        } catch (DomainInvariantException) {
        }
        Company::query()->findOrFail(1)->update(['negative_stock_mode' => 'BLOCK']);
        $this->expectException(DomainInvariantException::class);
        app(RequestNegativeStockOverride::class)($this->input($item->id, $locationId), $actor);
    }

    public function test_request_source_is_idempotent_and_workflow_rows_are_database_immutable(): void
    {
        [$item, $locationId] = $this->foundation('PRIOR_APPROVAL');
        $actor = User::factory()->create();
        $actor->givePermissionTo('inventory.negative-stock.request');
        $first = app(RequestNegativeStockOverride::class)($this->input($item->id, $locationId), $actor);
        $second = app(RequestNegativeStockOverride::class)($this->input($item->id, $locationId), $actor);
        $this->assertSame($first->id, $second->id);

        $this->expectException(QueryException::class);
        DB::table('negative_stock_override_requests')->where('id', $first->id)->update(['explanation' => 'rewritten']);
    }

    private function foundation(string $mode): array
    {
        [, $item] = $this->makeOrder();
        $item->update(['is_stocked' => true, 'valuation_method' => ValuationMethod::Fifo, 'avco_pool_scope' => null]);
        Company::query()->findOrFail(1)->update(['negative_stock_mode' => 'AUTHORISED_OVERRIDE', 'negative_stock_authorisation_mode' => $mode]);
        $locationId = app(CreateWarehouse::class)(['code' => 'main', 'name' => 'Main', 'location_code' => 'main', 'location_name' => 'Main'])->locations()->sole()->id;

        return [$item, $locationId];
    }

    public function test_a_client_supplied_available_qty_is_ignored_in_favour_of_live_stock(): void
    {
        [$item, $locationId] = $this->foundation('PRIOR_APPROVAL');
        $requester = User::factory()->create();
        $requester->givePermissionTo('inventory.negative-stock.request');

        // A requester claiming 4 of the 5 are on hand would otherwise obtain
        // authority for a 1-unit shortage and then issue all 5 against it.
        $request = app(RequestNegativeStockOverride::class)(
            array_merge($this->input($item->id, $locationId), ['available_qty' => '4.0000']),
            $requester,
        );

        $this->assertSame('0.0000', $request->available_qty);
        $this->assertSame('5.0000', $request->shortage_qty);
    }

    private function input(int $itemId, int $locationId, int $sourceId = 1): array
    {
        return ['item_id' => $itemId, 'location_id' => $locationId, 'movement_class' => 'MATERIAL_CONSUMPTION', 'requested_qty' => '5.0000', 'source_type' => 'TEST_ISSUE', 'source_id' => $sourceId, 'reason_code' => 'PAPERWORK_DELAY', 'explanation' => 'Receipt paperwork is delayed'];
    }
}
