<?php

namespace Tests\Feature\Modules\Inventory;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Support\AccountType;
use App\Modules\Inventory\Actions\CreateWarehouse;
use App\Modules\Inventory\Actions\DecideRecalculationApproval;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Actions\RequestStockRecalculation;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\RecalculationImpactVersion;
use App\Modules\Inventory\Models\RecalculationRequest;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\RecalculationApprovalDecision;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Support\UomDimension;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DecideRecalculationApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        Permission::findOrCreate('inventory.recalculation.request');
        Permission::findOrCreate('inventory.recalculation.approve');
        Company::query()->findOrFail(1)->update([
            'authorised_forward_recalculation_enabled' => true,
            'backdating_horizon_mode' => 'POSTING_FLOOR',
            'backdating_posting_floor_subtype' => 'FIXED_DATE',
            'backdating_posting_floor_fixed_date' => '2020-01-01',
            'recalculation_approval_ttl_hours' => 24,
        ]);
    }

    /** @return array{0: Item, 1: Uom, 2: Location} */
    private function makeAvcoItem(): array
    {
        $currency = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$']);
        Company::query()->findOrFail(1)->update(['base_currency_id' => $currency->id]);
        $uom = Uom::create(['code' => 'ea', 'name' => 'Each', 'dimension' => UomDimension::Count, 'factor_to_base' => '1']);
        $category = Category::create(['code' => 'raw', 'name' => 'Raw', 'default_valuation_method' => 'AVCO']);
        $item = Item::create([
            'code' => 'item', 'name' => 'Item', 'type' => 'RAW', 'stock_uom_id' => $uom->id, 'category_id' => $category->id,
            'is_stocked' => true, 'valuation_method' => ValuationMethod::Avco, 'avco_pool_scope' => AvcoPoolScope::CompanyItem,
            'inventory_account_id' => ChartOfAccount::query()->firstOr(fn () => ChartOfAccount::create(['code' => 'inv', 'name' => 'Inventory', 'type' => AccountType::Asset]))->id,
        ]);
        $location = app(CreateWarehouse::class)(['code' => 'main', 'name' => 'Main', 'location_code' => 'main', 'location_name' => 'Main'])->locations()->sole();

        return [$item, $uom, $location];
    }

    /** @return array{0: Item, 1: Uom, 2: Location, 3: RecalculationRequest, 4: User} requester */
    private function makeRequestWithConflict(): array
    {
        [$item, $uom, $location] = $this->makeAvcoItem();
        app(PostInventoryMovement::class)([
            'item_id' => $item->id, 'location_id' => $location->id, 'qty' => '5.0000', 'uom_id' => $uom->id,
            'moved_at' => Carbon::parse('2026-01-01'), 'source_type' => 'TEST_RECEIPT', 'source_id' => 1,
            'unit_cost' => '10.0000', 'currency_id' => Company::query()->findOrFail(1)->base_currency_id,
            'fx_rate' => '1.000000000', 'base_unit_cost' => '10.0000',
        ]);
        app(PostInventoryMovement::class)->issue([
            'item_id' => $item->id, 'location_id' => $location->id, 'qty' => '2.0000', 'uom_id' => $uom->id,
            'moved_at' => Carbon::parse('2026-01-10'), 'source_type' => 'TEST_ISSUE', 'source_id' => 2,
        ]);
        $requester = User::factory()->create();
        $requester->givePermissionTo('inventory.recalculation.request');
        $request = app(RequestStockRecalculation::class)([
            'item_id' => $item->id, 'location_id' => $location->id,
            'qty' => '10.0000', 'unit_cost' => '20.0000', 'currency_id' => Company::query()->findOrFail(1)->base_currency_id,
            'fx_rate' => '1.000000000', 'base_unit_cost' => '20.0000', 'moved_at' => Carbon::parse('2026-01-05'),
            'source_type' => 'TEST_BACKDATED_RECEIPT', 'source_id' => 3,
        ], $requester);

        return [$item, $uom, $location, $request, $requester];
    }

    public function test_notes_are_required(): void
    {
        [, , , $request] = $this->makeRequestWithConflict();
        $approver = User::factory()->create();
        $approver->givePermissionTo('inventory.recalculation.approve');

        $this->expectException(DomainInvariantException::class);
        app(DecideRecalculationApproval::class)($request->impactVersions->sole(), RecalculationApprovalDecision::Approved, '', $approver);
    }

    public function test_the_requester_cannot_decide_their_own_request(): void
    {
        [, , , $request, $requester] = $this->makeRequestWithConflict();
        $requester->givePermissionTo('inventory.recalculation.approve');

        $this->expectException(DomainInvariantException::class);
        app(DecideRecalculationApproval::class)($request->impactVersions->sole(), RecalculationApprovalDecision::Approved, 'checked', $requester);
    }

    public function test_actor_without_permission_is_rejected(): void
    {
        [, , , $request] = $this->makeRequestWithConflict();
        $actor = User::factory()->create();

        $this->expectException(DomainInvariantException::class);
        app(DecideRecalculationApproval::class)($request->impactVersions->sole(), RecalculationApprovalDecision::Approved, 'checked', $actor);
    }

    public function test_approving_sets_an_expiry_and_rejecting_does_not(): void
    {
        [, , , $request] = $this->makeRequestWithConflict();
        $approver = User::factory()->create();
        $approver->givePermissionTo('inventory.recalculation.approve');

        $approval = app(DecideRecalculationApproval::class)($request->impactVersions->sole(), RecalculationApprovalDecision::Approved, 'checked', $approver);

        $this->assertNotNull($approval->expires_at);
        $this->assertTrue($approval->expires_at->isSameHour(now()->addHours(24)));
    }

    public function test_rejecting_does_not_set_an_expiry(): void
    {
        [, , , $request] = $this->makeRequestWithConflict();
        $approver = User::factory()->create();
        $approver->givePermissionTo('inventory.recalculation.approve');

        $approval = app(DecideRecalculationApproval::class)($request->impactVersions->sole(), RecalculationApprovalDecision::Rejected, 'not justified', $approver);

        $this->assertNull($approval->expires_at);
    }

    public function test_a_retry_with_the_same_decision_is_idempotent(): void
    {
        [, , , $request] = $this->makeRequestWithConflict();
        $approver = User::factory()->create();
        $approver->givePermissionTo('inventory.recalculation.approve');
        $version = $request->impactVersions->sole();

        $first = app(DecideRecalculationApproval::class)($version, RecalculationApprovalDecision::Approved, 'checked', $approver);
        $second = app(DecideRecalculationApproval::class)($version, RecalculationApprovalDecision::Approved, 'checked', $approver);

        $this->assertSame($first->id, $second->id);
    }

    public function test_a_stale_version_cannot_be_decided(): void
    {
        [, , , $request] = $this->makeRequestWithConflict();
        $version = $request->impactVersions->sole();
        $version->update(['is_stale' => true]);
        $approver = User::factory()->create();
        $approver->givePermissionTo('inventory.recalculation.approve');

        $this->expectException(DomainInvariantException::class);
        app(DecideRecalculationApproval::class)($version->fresh(), RecalculationApprovalDecision::Approved, 'checked', $approver);
    }

    public function test_deciding_revalidates_and_generates_a_replacement_version_when_the_pool_changed_since_it_was_computed(): void
    {
        [$item, $uom, $location, $request] = $this->makeRequestWithConflict();
        $originalVersion = $request->impactVersions->sole();
        // A new, later issue posted after the request was created changes
        // what a fresh replay of the same proposed receipt would compute.
        app(PostInventoryMovement::class)->issue([
            'item_id' => $item->id, 'location_id' => $location->id, 'qty' => '1.0000', 'uom_id' => $uom->id,
            'moved_at' => Carbon::parse('2026-01-12'), 'source_type' => 'TEST_ISSUE', 'source_id' => 4,
        ]);
        $approver = User::factory()->create();
        $approver->givePermissionTo('inventory.recalculation.approve');

        $this->expectException(DomainInvariantException::class);
        try {
            app(DecideRecalculationApproval::class)($originalVersion, RecalculationApprovalDecision::Approved, 'checked', $approver);
        } finally {
            $this->assertTrue($originalVersion->fresh()->is_stale);
            $versions = RecalculationImpactVersion::query()->where('recalculation_request_id', $request->id)->orderBy('version_no')->get();
            $this->assertSame([1, 2], $versions->pluck('version_no')->all());
            $this->assertFalse($versions->last()->is_stale);
            $this->assertNotSame($originalVersion->valuation_adjustment, $versions->last()->valuation_adjustment);
        }
    }

    public function test_an_impact_versions_is_stale_flip_is_the_only_permitted_mutation(): void
    {
        [, , , $request] = $this->makeRequestWithConflict();
        $version = $request->impactVersions->sole();

        DB::table('recalculation_impact_versions')->where('id', $version->id)->update(['is_stale' => true]);
        $this->assertTrue($version->fresh()->is_stale);

        $this->expectException(QueryException::class);
        DB::table('recalculation_impact_versions')->where('id', $version->id)->update(['is_stale' => false]);
    }

    public function test_an_impact_versions_economic_columns_are_immutable(): void
    {
        [, , , $request] = $this->makeRequestWithConflict();
        $version = $request->impactVersions->sole();

        $this->expectException(QueryException::class);
        DB::table('recalculation_impact_versions')->where('id', $version->id)->update(['valuation_adjustment' => '999.0000']);
    }

    public function test_requests_and_approvals_are_fully_database_immutable(): void
    {
        [, , , $request] = $this->makeRequestWithConflict();
        $approver = User::factory()->create();
        $approver->givePermissionTo('inventory.recalculation.approve');
        $approval = app(DecideRecalculationApproval::class)($request->impactVersions->sole(), RecalculationApprovalDecision::Approved, 'checked', $approver);

        try {
            DB::table('recalculation_requests')->where('id', $request->id)->update(['qty' => '999.0000']);
            $this->fail('Expected recalculation_requests to be database-immutable.');
        } catch (QueryException) {
        }

        $this->expectException(QueryException::class);
        DB::table('recalculation_approvals')->where('id', $approval->id)->update(['notes' => 'rewritten']);
    }
}
