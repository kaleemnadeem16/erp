<?php

namespace Tests\Feature\Modules\Estimating;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Support\AccountType;
use App\Modules\Approvals\Actions\SaveApprovalSubjectSetting;
use App\Modules\Approvals\Support\ApprovalMode;
use App\Modules\Estimating\Actions\PreviewInventoryAvailability;
use App\Modules\Estimating\Actions\ResolveStockMixControls;
use App\Modules\Estimating\Actions\SetStockMixPolicy;
use App\Modules\Estimating\Models\StockMixPolicy;
use App\Modules\Estimating\Support\MaterialSelectionScope;
use App\Modules\Estimating\Support\MaterialSelectionSource;
use App\Modules\Estimating\Support\StockMixLevel;
use App\Modules\Estimating\Support\StockMixVerdict;
use App\Modules\Inventory\Actions\CreateWarehouse;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Actions\QueryMaterialCost;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Support\AvcoPoolStatus;
use App\Modules\Inventory\Support\Costing\CostReadSession;
use App\Modules\Inventory\Support\ItemType;
use App\Support\UomDimension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\PermissionedActor;
use Tests\Support\PurchasingActor;
use Tests\TestCase;

/**
 * ADR 0076: how tightly a company controls hand-chosen stock. FIFO stock is used so that two locations really cost
 * differently — with an average-cost item every source costs the same and a stricter level could never fire.
 */
class StockMixControlTest extends TestCase
{
    use RefreshDatabase;

    private Uom $kg;

    private Item $item;

    private Category $category;

    private User $actor;

    /** @var array<string, Location> */
    private array $locations = [];

    private int $sourceId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        foreach (['estimating.stock_mix_policy.manage', 'estimating.policy-history.view', 'estimating.stock_mix.choose', 'estimating.stock_mix.approve'] as $permission) {
            Permission::findOrCreate($permission);
        }
        $this->kg = Uom::create(['code' => 'kg', 'name' => 'Kilogram', 'dimension' => UomDimension::Mass, 'factor_to_base' => '1', 'is_base' => true]);
        $this->category = Category::create(['code' => 'RAW', 'name' => 'Raw', 'default_valuation_method' => 'FIFO']);
        $this->item = Item::create([
            'code' => 'RESIN', 'name' => 'Resin', 'type' => ItemType::Raw, 'stock_uom_id' => $this->kg->id, 'category_id' => $this->category->id,
            'is_stocked' => true, 'inventory_account_id' => ChartOfAccount::create(['code' => 'inv', 'name' => 'Inventory', 'type' => AccountType::Asset])->id,
        ]);
        $currency = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$']);
        Company::query()->findOrFail(1)->update(['base_currency_id' => $currency->id]);
        $this->actor = PermissionedActor::grant(User::factory()->create(), ['estimating.stock_mix_policy.manage', 'approvals.settings.manage']);
    }

    private function receive(string $warehouse, string $qty, string $unitCost): void
    {
        $this->locations[$warehouse] ??= app(CreateWarehouse::class)([
            'code' => $warehouse, 'name' => strtoupper($warehouse), 'location_code' => 'main', 'location_name' => 'Main',
        ], PurchasingActor::make(['inventory.warehouse.manage']))->locations()->sole();
        $currency = Currency::query()->where('code', 'USD')->firstOrFail();
        app(PostInventoryMovement::class)([
            'item_id' => $this->item->id, 'location_id' => $this->locations[$warehouse]->id, 'qty' => $qty, 'uom_id' => $this->kg->id,
            'unit_cost' => $unitCost, 'base_unit_cost' => $unitCost, 'currency_id' => $currency->id, 'fx_rate' => '1',
            'source_type' => 'TEST_RECEIPT', 'source_id' => ++$this->sourceId, 'moved_at' => now(),
        ]);
    }

    private function setLevel(?StockMixLevel $level, ?string $threshold = null, string $reason = 'Company decision'): StockMixPolicy
    {
        return app(SetStockMixPolicy::class)(MaterialSelectionScope::Company, null, $level, $threshold, $reason, $this->actor);
    }

    /**
     * Preview 10 kg needed with the cheap store (a, 2.00/kg, 10 kg) and the dear store (b, 3.00/kg, 10 kg).
     * The FIFO proposal draws the cheap store, so choosing b is 50% dearer per kg.
     *
     * @param  list<array{0: string, 1: string}>  $draws  warehouse code and quantity to take from it
     * @param  list<array<string, mixed>>  $notes
     * @return array<string, mixed>
     */
    private function preview(array $draws, array $notes = []): array
    {
        $this->receive('a', '10', '2.0000');
        $this->receive('b', '10', '3.0000');
        $lines = array_map(fn (array $pair): array => $this->draw($pair[0], $pair[1]), $draws);
        $leaf = [['item' => ['id' => $this->item->id, 'code' => 'RESIN', 'name' => 'Resin'], 'standard_quantity' => '10', 'uom' => ['id' => $this->kg->id, 'code' => 'kg', 'name' => 'Kilogram']]];

        return $this->availability($leaf, true, $lines, $notes)['data'];
    }

    /**
     * @param  list<array<string, mixed>>  $leaf
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>  $notes
     * @return array{data: array<string, mixed>, evidence: array<string, mixed>}
     */
    private function availability(array $leaf, bool $includeCost, array $lines, array $notes): array
    {
        return app(QueryMaterialCost::class)->scenario(
            fn (CostReadSession $session): array => app(PreviewInventoryAvailability::class)($session, $leaf, [], null, $includeCost, [], $lines, $notes),
        );
    }

    /** @return array<string, mixed> */
    private function draw(string $warehouse, string $qty): array
    {
        return ['item_id' => $this->item->id, 'location_id' => $this->locations[$warehouse]->id, 'batch_id' => null, 'quantity' => $qty, 'uom_id' => $this->kg->id];
    }

    private function mixOf(array $data): array
    {
        return $data['items'][0]['selected_mix'];
    }

    public function test_by_default_a_mix_is_allowed_and_nothing_is_required(): void
    {
        $data = $this->preview([['b', '10']]);

        $this->assertSame('INFORMATION', $data['items'][0]['mix_control']['level']['code']);
        $this->assertSame('BUILT_IN_DEFAULT', $data['items'][0]['mix_control']['source']);
        $this->assertSame('NOT_CONTROLLED', $this->mixOf($data)['control']['status']);
        $this->assertTrue($this->mixOf($data)['control']['can_adopt']);
    }

    public function test_not_allowed_refuses_a_mix_but_still_proposes(): void
    {
        $this->setLevel(StockMixLevel::NotAllowed);
        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('does not allow choosing stock by hand');

        $this->preview([['b', '10']]);
    }

    public function test_not_allowed_still_gives_a_normal_proposal_when_no_mix_is_asked_for(): void
    {
        $this->setLevel(StockMixLevel::NotAllowed);

        $data = $this->preview([]);

        $this->assertSame('NOT_ALLOWED', $data['items'][0]['mix_control']['level']['code']);
        $this->assertNull($data['items'][0]['selected_mix']);
        $this->assertNotNull($data['items'][0]['recommendation']);
    }

    public function test_a_costlier_mix_over_the_limit_needs_a_reason_and_one_given_satisfies_it(): void
    {
        $this->setLevel(StockMixLevel::ReasonRequired, '10');

        $missing = $this->mixOf($this->preview([['b', '10']]));
        $this->assertSame(StockMixVerdict::REASON_MISSING, $missing['control']['status']);
        $this->assertFalse($missing['control']['can_adopt']);
        $this->assertSame('50', $missing['control']['observed_percent'] === null ? null : (string) (float) $missing['control']['observed_percent']);
        $this->assertSame('10', $missing['control']['threshold_percent']);

        $given = $this->mixOf($this->preview([['b', '10']], [['item_id' => $this->item->id, 'note' => 'The job site is next to store b']]));
        $this->assertSame(StockMixVerdict::REASON_PROVIDED, $given['control']['status']);
        $this->assertTrue($given['control']['can_adopt']);
        $this->assertSame('The job site is next to store b', $given['control']['note']);
    }

    public function test_a_mix_within_the_limit_or_cheaper_than_the_proposal_is_not_flagged(): void
    {
        $this->setLevel(StockMixLevel::ReasonRequired, '60');
        $within = $this->mixOf($this->preview([['b', '10']]));
        $this->assertSame(StockMixVerdict::WITHIN_LIMIT, $within['control']['status']);
    }

    public function test_a_partial_mix_is_compared_on_unit_cost_so_it_does_not_look_cheap(): void
    {
        $this->setLevel(StockMixLevel::ReasonRequired, '10');

        // 4 kg of the dear store costs 12 against a 10 kg proposal costing 20: cheaper in total, but 50% dearer per kg.
        $partial = $this->mixOf($this->preview([['b', '4']]));

        $this->assertSame(StockMixVerdict::REASON_MISSING, $partial['control']['status']);
    }

    public function test_the_judgement_is_the_same_without_the_cost_capability_but_the_numbers_are_hidden(): void
    {
        $this->setLevel(StockMixLevel::ReasonRequired, '10');
        $this->receive('a', '10', '2.0000');
        $this->receive('b', '10', '3.0000');
        $leaf = [['item' => ['id' => $this->item->id, 'code' => 'RESIN', 'name' => 'Resin'], 'standard_quantity' => '10', 'uom' => ['id' => $this->kg->id, 'code' => 'kg', 'name' => 'Kilogram']]];

        $blind = $this->availability($leaf, false, [$this->draw('b', '10')], [])['data']['items'][0]['selected_mix']['control'];

        $this->assertSame(StockMixVerdict::REASON_MISSING, $blind['status']);
        $this->assertNull($blind['observed_percent']);
        $this->assertNull($blind['extra_cost']);
    }

    public function test_the_approval_level_needs_approvals_switched_on_and_then_names_the_approval(): void
    {
        try {
            $this->setLevel(StockMixLevel::ApprovalRequired, '10');
            $this->fail('Approval level must be refused while approvals are off.');
        } catch (DomainInvariantException $e) {
            $this->assertSame('level', $e->field ?? 'level');
        }

        app(SaveApprovalSubjectSetting::class)('STOCK_MIX', ApprovalMode::Policy, $this->actor);
        $this->setLevel(StockMixLevel::ApprovalRequired, '10');

        $mix = $this->mixOf($this->preview([['b', '10']]));
        $this->assertSame(StockMixVerdict::APPROVAL_NEEDED, $mix['control']['status']);
        $this->assertFalse($mix['control']['can_adopt']);
        $this->assertNull($mix['control']['approval']['policy']);
        $this->assertStringContainsString('No approval policy applies', $mix['control']['approval']['note']);
    }

    public function test_a_stored_approval_level_fails_closed_when_approvals_are_later_switched_off(): void
    {
        app(SaveApprovalSubjectSetting::class)('STOCK_MIX', ApprovalMode::Policy, $this->actor);
        $this->setLevel(StockMixLevel::ApprovalRequired, '10');
        app(SaveApprovalSubjectSetting::class)('STOCK_MIX', ApprovalMode::None, $this->actor);

        $mix = $this->mixOf($this->preview([['b', '10']]));

        $this->assertSame(StockMixVerdict::BLOCKED, $mix['control']['status']);
        $this->assertFalse($mix['control']['can_adopt']);
    }

    public function test_a_mix_that_cannot_be_costed_is_not_judged_rather_than_passed(): void
    {
        $this->setLevel(StockMixLevel::ReasonRequired, '10');
        // A non-FIFO item with no cost evidence at all.
        $this->item->update(['valuation_method' => 'AVCO']);
        $this->receive('a', '10', '2.0000');
        $this->receive('b', '10', '3.0000');
        AvcoPool::query()->update(['status' => AvcoPoolStatus::Frozen]);
        $leaf = [['item' => ['id' => $this->item->id, 'code' => 'RESIN', 'name' => 'Resin'], 'standard_quantity' => '10', 'uom' => ['id' => $this->kg->id, 'code' => 'kg', 'name' => 'Kilogram']]];

        $control = $this->availability($leaf, true, [$this->draw('b', '10')], [])['data']['items'][0]['selected_mix']['control'];

        $this->assertSame(StockMixVerdict::NOT_JUDGED, $control['status']);
        $this->assertFalse($control['can_adopt']);
    }

    // ---- storage, governance and forward compatibility ----

    public function test_changes_are_governed_kept_as_history_and_a_repeat_writes_nothing(): void
    {
        $first = $this->setLevel(StockMixLevel::ReasonRequired, '10');
        $same = $this->setLevel(StockMixLevel::ReasonRequired, '10', 'Again');
        $this->assertSame($first->id, $same->id);

        $second = $this->setLevel(StockMixLevel::ReasonRequired, '25');
        $this->assertSame('SUPERSEDED', $first->refresh()->status->value);
        $this->assertSame('ACTIVE', $second->status->value);
        $this->assertSame(2, StockMixPolicy::query()->count());

        $cleared = $this->setLevel(null);
        $this->assertNull($cleared->level);
        $this->assertSame(StockMixLevel::Information, app(ResolveStockMixControls::class)([$this->item->id])[$this->item->id]->level);
    }

    public function test_a_reason_and_the_right_percentage_are_required(): void
    {
        foreach ([
            fn () => $this->setLevel(StockMixLevel::Information, null, '  '),
            fn () => $this->setLevel(StockMixLevel::ReasonRequired, null),
            fn () => $this->setLevel(StockMixLevel::ReasonRequired, '0'),
            fn () => $this->setLevel(StockMixLevel::Information, '10'),
            fn () => $this->setLevel(null),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected the change to be refused.');
            } catch (DomainInvariantException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, StockMixPolicy::query()->count());
    }

    public function test_rows_cannot_be_edited_or_deleted(): void
    {
        $row = $this->setLevel(StockMixLevel::NotAllowed);
        try {
            $row->update(['level' => StockMixLevel::Information]);
            $this->fail('edit');
        } catch (DomainInvariantException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(DomainInvariantException::class);
        $row->delete();
    }

    public function test_category_and_item_scope_already_resolve_so_widening_later_is_not_a_redesign(): void
    {
        $child = Category::create(['code' => 'RES', 'name' => 'Resins', 'parent_id' => $this->category->id]);
        $this->item->update(['category_id' => $child->id]);
        $set = app(SetStockMixPolicy::class);
        $set(MaterialSelectionScope::Company, null, StockMixLevel::Information, null, 'Company', $this->actor);

        $set(MaterialSelectionScope::ItemCategory, $this->category->id, StockMixLevel::ReasonRequired, '20', 'Category', $this->actor);
        $viaAncestor = app(ResolveStockMixControls::class)([$this->item->id])[$this->item->id];
        $this->assertSame(StockMixLevel::ReasonRequired, $viaAncestor->level);
        $this->assertSame(MaterialSelectionSource::ItemCategory, $viaAncestor->source);

        $set(MaterialSelectionScope::Item, $this->item->id, StockMixLevel::NotAllowed, null, 'Item', $this->actor);
        $item = app(ResolveStockMixControls::class)([$this->item->id])[$this->item->id];
        $this->assertSame(StockMixLevel::NotAllowed, $item->level);
        $this->assertSame(MaterialSelectionSource::Item, $item->source);
    }

    public function test_the_api_is_company_only_for_now_and_needs_the_manage_permission(): void
    {
        $body = ['scope_type' => 'COMPANY', 'level' => 'REASON_REQUIRED', 'threshold_percent' => '10', 'reason' => 'Tighten up'];
        $nobody = User::factory()->create();
        $this->actingAs($nobody)->postJson('/api/v1/stock-mix-policies', $body)->assertForbidden();
        $this->actingAs($nobody)->getJson('/api/v1/stock-mix-policies')->assertForbidden();

        $manager = User::factory()->create();
        $manager->givePermissionTo('estimating.stock_mix_policy.manage');
        $this->actingAs($manager)->postJson('/api/v1/stock-mix-policies', $body)
            ->assertCreated()
            ->assertJsonPath('data.level.code', 'REASON_REQUIRED')
            ->assertJsonPath('data.threshold_percent', '10.0000')
            ->assertJsonPath('data.scope_name', 'Whole company');

        $this->actingAs($manager)->postJson('/api/v1/stock-mix-policies', ['scope_type' => 'ITEM', 'scope_id' => $this->item->id] + $body)
            ->assertUnprocessable()->assertJsonValidationErrors('scope_type');
        $this->actingAs($manager)->postJson('/api/v1/stock-mix-policies', ['scope_type' => 'COMPANY', 'level' => 'REASON_REQUIRED', 'reason' => 'x'])
            ->assertUnprocessable();
        $this->actingAs($manager)->postJson('/api/v1/stock-mix-policies', ['scope_type' => 'COMPANY', 'level' => 'REASON_REQUIRED', 'threshold_percent' => '10'])
            ->assertJsonValidationErrors('reason');

        $auditor = User::factory()->create();
        $auditor->givePermissionTo('estimating.policy-history.view');
        $this->actingAs($auditor)->getJson('/api/v1/stock-mix-policies?filter[status]=ACTIVE')->assertOk()->assertJsonCount(1, 'data');
    }
}
