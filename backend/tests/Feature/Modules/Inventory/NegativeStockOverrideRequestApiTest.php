<?php

namespace Tests\Feature\Modules\Inventory;

use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Actions\CreateWarehouse;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Modules\Purchasing\PurchaseOrderFoundationTest;
use Tests\Support\PurchasingActor;

/**
 * The negative-stock override HTTP surface — exposed per
 * docs/phases/phase-2-purchasing-stock.md#close-out. The workflow rules
 * themselves (shortage computation, policy eligibility, separation of
 * duties, immutability) are covered at the Action level by
 * NegativeStockOverrideWorkflowTest; this only proves the controller/
 * request layer wires them correctly.
 */
class NegativeStockOverrideRequestApiTest extends PurchaseOrderFoundationTest
{
    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        foreach ([
            'inventory.negative-stock.request', 'inventory.negative-stock.override',
            'inventory.negative-stock.approve', 'inventory.negative-stock.review', 'inventory.stock.view',
            'inventory.availability.view', 'inventory.movement.view', 'inventory.valuation.view',
        ] as $permission) {
            Permission::findOrCreate($permission);
        }
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/negative-stock-override-requests')->assertUnauthorized();
    }

    public function test_prior_approval_lifecycle_requires_an_independent_decider(): void
    {
        [$itemId, $locationId] = $this->foundation('PRIOR_APPROVAL');
        $requester = $this->permittedUser('inventory.negative-stock.request');

        $requestId = $this->actingAs($requester)->postJson('/api/v1/negative-stock-override-requests', $this->input($itemId, $locationId))
            ->assertCreated()->assertJsonPath('data.available_qty', '0.0000')->assertJsonPath('data.shortage_qty', '5.0000')->assertJsonPath('data.authorisation_mode', 'PRIOR_APPROVAL')->json('data.id');

        $requester->givePermissionTo('inventory.negative-stock.approve');
        $this->actingAs($requester)->postJson("/api/v1/negative-stock-override-requests/{$requestId}/decide", ['decision' => 'APPROVED', 'notes' => 'self'])
            ->assertUnprocessable();

        $approver = $this->permittedUser('inventory.negative-stock.approve');
        $this->actingAs($approver)->postJson("/api/v1/negative-stock-override-requests/{$requestId}/decide", ['decision' => 'APPROVED', 'notes' => 'Evidence checked'])
            ->assertOk()->assertJsonPath('data.approval.decision', 'APPROVED')->assertJsonPath('data.approval.decided_by', $approver->id);
    }

    public function test_override_reads_expose_shortage_quantities_but_no_settlement_or_cost_data(): void
    {
        [$itemId, $locationId] = $this->foundation('PRIOR_APPROVAL');
        $requester = $this->permittedUser('inventory.negative-stock.request');
        $requestId = $this->actingAs($requester)->postJson('/api/v1/negative-stock-override-requests', $this->input($itemId, $locationId))
            ->assertCreated()->json('data.id');

        foreach ([
            "/api/v1/negative-stock-override-requests/{$requestId}?include=item,location,approval,review",
            '/api/v1/negative-stock-override-requests?include=item,location,approval,review',
        ] as $url) {
            $response = $this->actingAs($this->permittedUser('inventory.stock.view'))->getJson($url)->assertOk();
            $record = str_contains($url, "{$requestId}?") ? $response->json('data') : $response->json('data.0');

            $this->assertSame('5.0000', $record['shortage_qty']);
            foreach (['provisional_unit_cost', 'actual_unit_cost', 'unit_cost_difference', 'valuation_adjustment', 'settlements'] as $field) {
                $this->assertArrayNotHasKey($field, $record);
            }
        }
    }

    public function test_reads_need_a_workflow_duty_or_stock_position_access(): void
    {
        [$itemId, $locationId] = $this->foundation('PRIOR_APPROVAL');
        $requester = $this->permittedUser('inventory.negative-stock.request');
        $requestId = $this->actingAs($requester)->postJson('/api/v1/negative-stock-override-requests', $this->input($itemId, $locationId))
            ->assertCreated()->json('data.id');

        // An unrelated signed-in user, and one who only sees item-level availability or the ledger, learn nothing about the shortage.
        foreach ([
            User::factory()->create(),
            $this->permittedUser('inventory.availability.view'),
            $this->permittedUser('inventory.movement.view'),
            $this->permittedUser('inventory.valuation.view'),
        ] as $outsider) {
            $this->actingAs($outsider)->getJson('/api/v1/negative-stock-override-requests')->assertForbidden();
            $this->actingAs($outsider)->getJson("/api/v1/negative-stock-override-requests/{$requestId}")->assertForbidden();
            $this->actingAs($outsider)->getJson("/api/v1/negative-stock-override-requests/{$requestId}?include=item,location,batch,approval,review")->assertForbidden();
        }

        // Each workflow duty, and read-only stock position access, can list and open it.
        foreach ([
            'inventory.negative-stock.request', 'inventory.negative-stock.override', 'inventory.negative-stock.approve',
            'inventory.negative-stock.review', 'inventory.stock.view',
        ] as $permission) {
            $reader = $this->permittedUser($permission);
            $this->actingAs($reader)->getJson('/api/v1/negative-stock-override-requests')->assertOk()->assertJsonCount(1, 'data');
            $this->actingAs($reader)->getJson("/api/v1/negative-stock-override-requests/{$requestId}")->assertOk()->assertJsonPath('data.shortage_qty', '5.0000');
        }
    }

    public function test_a_read_only_stock_viewer_cannot_act_on_a_request(): void
    {
        [$itemId, $locationId] = $this->foundation('PRIOR_APPROVAL');
        $requester = $this->permittedUser('inventory.negative-stock.request');
        $requestId = $this->actingAs($requester)->postJson('/api/v1/negative-stock-override-requests', $this->input($itemId, $locationId))
            ->assertCreated()->json('data.id');

        $viewer = $this->permittedUser('inventory.stock.view');
        $this->actingAs($viewer)->postJson('/api/v1/negative-stock-override-requests', $this->input($itemId, $locationId, 9))->assertForbidden();
        $this->assertDatabaseCount('negative_stock_override_requests', 1);
        $this->actingAs($viewer)->postJson("/api/v1/negative-stock-override-requests/{$requestId}/decide", ['decision' => 'APPROVED', 'notes' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->postJson("/api/v1/negative-stock-override-requests/{$requestId}/review", ['outcome' => 'JUSTIFIED', 'notes' => 'x'])->assertForbidden();
    }

    public function test_immediate_review_lifecycle_requires_an_independent_reviewer_by_default(): void
    {
        [$itemId, $locationId] = $this->foundation('IMMEDIATE_WITH_REVIEW');
        $requester = $this->permittedUser(['inventory.negative-stock.override', 'inventory.negative-stock.review']);

        $requestId = $this->actingAs($requester)->postJson('/api/v1/negative-stock-override-requests', $this->input($itemId, $locationId, 2))
            ->assertCreated()->assertJsonPath('data.authorisation_mode', 'IMMEDIATE_WITH_REVIEW')->json('data.id');

        $this->actingAs($requester)->postJson("/api/v1/negative-stock-override-requests/{$requestId}/review", ['outcome' => 'JUSTIFIED', 'notes' => 'self'])
            ->assertUnprocessable();

        $reviewer = $this->permittedUser('inventory.negative-stock.review');
        $this->actingAs($reviewer)->postJson("/api/v1/negative-stock-override-requests/{$requestId}/review", ['outcome' => 'FLAGGED', 'notes' => 'Follow up required'])
            ->assertOk()->assertJsonPath('data.review.outcome', 'FLAGGED')->assertJsonPath('data.review.reviewed_by', $reviewer->id);
    }

    public function test_store_rejects_a_disabled_movement_class(): void
    {
        [$itemId, $locationId] = $this->foundation('PRIOR_APPROVAL');
        $requester = $this->permittedUser('inventory.negative-stock.request');

        $input = $this->input($itemId, $locationId);
        $input['movement_class'] = 'SUPPLIER_RETURN';
        $this->actingAs($requester)->postJson('/api/v1/negative-stock-override-requests', $input)
            ->assertUnprocessable();
    }

    public function test_store_is_idempotent_on_source(): void
    {
        [$itemId, $locationId] = $this->foundation('PRIOR_APPROVAL');
        $requester = $this->permittedUser('inventory.negative-stock.request');

        $first = $this->actingAs($requester)->postJson('/api/v1/negative-stock-override-requests', $this->input($itemId, $locationId))
            ->assertCreated()->json('data.id');
        $second = $this->actingAs($requester)->postJson('/api/v1/negative-stock-override-requests', $this->input($itemId, $locationId))
            ->assertCreated()->json('data.id');

        $this->assertSame($first, $second);
    }

    public function test_index_filters_by_movement_class(): void
    {
        [$itemId, $locationId] = $this->foundation('PRIOR_APPROVAL');
        $requester = $this->permittedUser('inventory.negative-stock.request');
        $this->actingAs($requester)->postJson('/api/v1/negative-stock-override-requests', $this->input($itemId, $locationId))->assertCreated();

        $this->actingAs($requester)->getJson('/api/v1/negative-stock-override-requests?filter[movement_class]=MATERIAL_CONSUMPTION')
            ->assertOk()->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/negative-stock-override-requests?filter[movement_class]=BOGUS')
            ->assertUnprocessable()->assertJsonValidationErrors('filter.movement_class');
    }

    public function test_another_companys_request_is_not_visible(): void
    {
        [$itemId, $locationId] = $this->foundation('PRIOR_APPROVAL');
        $requester = $this->permittedUser('inventory.negative-stock.request');
        $requestId = $this->actingAs($requester)->postJson('/api/v1/negative-stock-override-requests', $this->input($itemId, $locationId))->json('data.id');

        $otherCompanyId = DB::table('companies')->insertGetId(['name' => 'Other Co', 'created_at' => now(), 'updated_at' => now()]);
        $this->app->instance(CompanyContext::class, new class($otherCompanyId) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });

        $this->actingAs($this->permittedUser('inventory.stock.view'))->getJson("/api/v1/negative-stock-override-requests/{$requestId}")->assertNotFound();
    }

    /** @return array{int, int} */
    private function foundation(string $mode): array
    {
        [, $item] = $this->makeOrder();
        $item->update(['is_stocked' => true, 'valuation_method' => ValuationMethod::Fifo, 'avco_pool_scope' => null]);
        Company::query()->findOrFail(1)->update(['negative_stock_mode' => 'AUTHORISED_OVERRIDE', 'negative_stock_authorisation_mode' => $mode]);
        $locationId = app(CreateWarehouse::class)(['code' => 'main', 'name' => 'Main', 'location_code' => 'main', 'location_name' => 'Main'], PurchasingActor::make(['inventory.warehouse.manage']))->locations()->sole()->id;

        return [$item->id, $locationId];
    }

    /** @return array<string, mixed> */
    private function input(int $itemId, int $locationId, int $sourceId = 1): array
    {
        return [
            'item_id' => $itemId, 'location_id' => $locationId, 'movement_class' => 'MATERIAL_CONSUMPTION',
            'requested_qty' => '5.0000', 'source_type' => 'TEST_ISSUE', 'source_id' => $sourceId,
            'reason_code' => 'PAPERWORK_DELAY', 'explanation' => 'Receipt paperwork is delayed',
        ];
    }

    /** @param  string|array<int, string>  $permissions */
    private function permittedUser(string|array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }
}
