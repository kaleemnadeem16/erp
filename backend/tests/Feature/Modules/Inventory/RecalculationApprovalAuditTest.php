<?php

namespace Tests\Feature\Modules\Inventory;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Support\AccountType;
use App\Modules\Inventory\Actions\CreateWarehouse;
use App\Modules\Inventory\Actions\DecideRecalculationApproval;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Actions\Recalculation\RegenerationLimit;
use App\Modules\Inventory\Actions\RequestStockRecalculation;
use App\Modules\Inventory\Actions\ResumeRecalculationRegeneration;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\RecalculationRequest;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\RecalculationApprovalDecision;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Support\UomDimension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RecalculationApprovalAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        Permission::findOrCreate('inventory.recalculation.request');
        Permission::findOrCreate('inventory.recalculation.approve');
        Permission::findOrCreate('inventory.recalculation.approve-high-risk');
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
    private function makeRequestWithConflict(bool $highRisk = false): array
    {
        if ($highRisk) {
            // Any nonzero adjustment clears this threshold — the exact
            // magnitude doesn't matter, only that the version is classified
            // HIGH_RISK before DecideRecalculationApproval sees it.
            Company::query()->findOrFail(1)->update(['recalculation_high_risk_threshold_base_amount' => '1.0000']);
        }
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

    private function approver(bool $elevated = false): User
    {
        $actor = User::factory()->create();
        $actor->givePermissionTo('inventory.recalculation.approve');
        if ($elevated) {
            $actor->givePermissionTo('inventory.recalculation.approve-high-risk');
        }

        return $actor;
    }

    public function test_two_ordinary_approvers_cannot_complete_a_high_risk_version(): void
    {
        [, , , $request] = $this->makeRequestWithConflict(true);
        $version = $request->impactVersions->sole();
        app(DecideRecalculationApproval::class)($version, RecalculationApprovalDecision::Approved, 'first', $this->approver());
        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('dedicated higher-risk authority');
        app(DecideRecalculationApproval::class)($version, RecalculationApprovalDecision::Approved, 'second', $this->approver());
    }

    public function test_closing_a_period_requires_fresh_high_risk_review_even_when_amounts_are_unchanged(): void
    {
        [, , , $request] = $this->makeRequestWithConflict();
        $version = $request->impactVersions->sole();
        $year = FiscalYear::query()->create(['name' => 'FY2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        AccountingPeriod::query()->create([
            'fiscal_year_id' => $year->id, 'period_no' => 1,
            'name' => 'January', 'starts_on' => '2026-01-01', 'ends_on' => '2026-01-31', 'status' => 'CLOSED',
        ]);
        try {
            app(DecideRecalculationApproval::class)($version, RecalculationApprovalDecision::Approved, 'check', $this->approver());
            $this->fail('A changed period must stale the old version.');
        } catch (DomainInvariantException $e) {
            $this->assertStringContainsString('fresh review', $e->getMessage());
        }
        $replacement = $request->currentImpactVersion();
        $this->assertSame($version->valuation_adjustment, $replacement->valuation_adjustment);
        $this->assertSame('HIGH_RISK', $replacement->risk_classification->value);
        $this->assertSame(0, $replacement->approvals()->count());
    }

    public function test_unchanged_net_delta_does_not_hide_changed_stock_history(): void
    {
        [$item, $uom, $location, $request] = $this->makeRequestWithConflict();
        $version = $request->impactVersions->sole();
        app(PostInventoryMovement::class)([
            'item_id' => $item->id, 'location_id' => $location->id, 'qty' => '2', 'uom_id' => $uom->id,
            'unit_cost' => '10', 'base_unit_cost' => '10', 'fx_rate' => '1',
            'currency_id' => Company::query()->findOrFail(1)->base_currency_id,
            'moved_at' => Carbon::parse('2026-01-11'), 'source_type' => 'TEST_RECEIPT', 'source_id' => 4,
        ]);
        try {
            app(DecideRecalculationApproval::class)($version, RecalculationApprovalDecision::Approved, 'check', $this->approver());
            $this->fail('Changed stock history must trigger a replacement.');
        } catch (DomainInvariantException) {
        }
        $replacement = $request->currentImpactVersion();
        $this->assertSame($version->valuation_adjustment, $replacement->valuation_adjustment);
        $this->assertTrue($version->fresh()->is_stale);
        $this->assertNotSame($version->original_qty_on_hand, $replacement->original_qty_on_hand);
        $this->assertSame(0, app(RegenerationLimit::class)->invalidations($request));
    }

    public function test_post_approval_regeneration_pauses_resumes_and_escalates_without_resetting_history(): void
    {
        [$item, $uom, $location, $request] = $this->makeRequestWithConflict(true);
        Permission::findOrCreate('inventory.recalculation.review-regeneration');
        $reviewer = $this->approver();
        $reviewer->givePermissionTo('inventory.recalculation.review-regeneration');
        $first = $this->approver();
        $second = $this->approver(true);
        $limit = app(RegenerationLimit::class);
        for ($i = 1; $i <= 6; $i++) {
            $version = $request->currentImpactVersion();
            app(DecideRecalculationApproval::class)($version, RecalculationApprovalDecision::Approved, 'first', $first);
            app(PostInventoryMovement::class)([
                'item_id' => $item->id, 'location_id' => $location->id, 'qty' => '1', 'uom_id' => $uom->id,
                'unit_cost' => '10', 'base_unit_cost' => '10', 'fx_rate' => '1',
                'currency_id' => Company::query()->findOrFail(1)->base_currency_id,
                'moved_at' => Carbon::parse('2026-01-10')->addDays($i), 'source_type' => 'TEST_LATER', 'source_id' => $i,
            ]);
            try {
                app(DecideRecalculationApproval::class)($version, RecalculationApprovalDecision::Approved, 'second', $second);
                $this->fail('Must revalidate before second approval.');
            } catch (DomainInvariantException) {
            }
            $this->assertSame($i, $limit->invalidations($request));
            if ($i % 3 !== 0) {
                $this->assertNotNull($request->currentImpactVersion());

                continue;
            }
            $this->assertNull($request->currentImpactVersion());
            $this->assertSame($i === 3 ? 'PRIORITY_REVIEW' : 'HIGHER_SEVERITY', $limit->pending($request)['escalation']);
            $resume = app(ResumeRecalculationRegeneration::class);
            if ($i === 6) {
                try {
                    $resume($version, 'Coordinated', $reviewer);
                    $this->fail('Second cap hit requires elevated authority.');
                } catch (DomainInvariantException $e) {
                    $this->assertStringContainsString('higher-risk authority', $e->getMessage());
                }
                $reviewer->givePermissionTo('inventory.recalculation.approve-high-risk');
            }
            $replacement = $resume($version, 'Coordinated', $reviewer);
            $this->assertSame($replacement->id, $resume($version, 'Coordinated', $reviewer)->id);
            $this->assertSame(0, $replacement->approvals()->count());
            $this->assertSame($i, $limit->invalidations($request));
        }
    }
}
