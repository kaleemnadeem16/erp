<?php

namespace Tests\Feature\Modules\Sales;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Estimating\Actions\ActivateAssemblyVersion;
use App\Modules\Estimating\Models\Assembly;
use App\Modules\Estimating\Models\AssemblyVersion;
use App\Modules\Estimating\Models\ResourceRate;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\AvcoPoolStatus;
use App\Modules\Inventory\Support\ItemType;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Support\Financial\Certainty;
use App\Modules\Sales\Support\Financial\FinancialResult;
use App\Modules\Sales\Support\Financial\SettlementRule;
use App\Modules\Sales\Support\Kernel\OperationIntent;
use App\Modules\Sales\Support\Kernel\SalesActorContext;
use App\Modules\Sales\Support\Pricing\PriceCandidate;
use App\Modules\Sales\Support\Pricing\PricingInput;
use App\Modules\Sales\Support\Pricing\PricingResult;
use App\Modules\Sales\Support\Pricing\PricingSource;
use App\Modules\Sales\Support\Pricing\ResolveSalesPrice;
use App\Support\CompanyContext;
use App\Support\UomDimension;
use Brick\Math\RoundingMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

/** PR0 — the internal pricing kernel over the real C1/C2 cost adapters, company scoping and Party identity (R3, 0115, 0110). */
class PricingKernelTest extends TestCase
{
    use RefreshDatabase;

    private Currency $usd;

    private Uom $kg;

    private Uom $hour;

    private Uom $sqm;

    private Category $category;

    private Party $prospect;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usd = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2]);
        Company::findOrFail(1)->update(['base_currency_id' => $this->usd->id]);
        $this->kg = Uom::create(['code' => 'kg', 'name' => 'Kilogram', 'dimension' => UomDimension::Mass, 'factor_to_base' => '1', 'is_base' => true]);
        $this->hour = Uom::create(['code' => 'hr', 'name' => 'Hour', 'dimension' => UomDimension::Time, 'factor_to_base' => '1', 'is_base' => true]);
        $this->sqm = Uom::create(['code' => 'sqm', 'name' => 'Square metre', 'dimension' => UomDimension::Area, 'factor_to_base' => '1', 'is_base' => true]);
        $this->category = Category::create(['code' => 'RAW', 'name' => 'Raw', 'default_valuation_method' => 'AVCO']);
        $this->prospect = Party::create(['code' => 'PROSPECT', 'name' => 'Prospect Ltd']);
        $this->user = User::factory()->create();
    }

    private function input(PricingSource $source, string $quantity, ?int $uom, ?User $actor = null, ?int $party = null): PricingInput
    {
        return new PricingInput(SalesActorContext::forUser($actor ?? $this->user, 'test'), $party ?? $this->prospect->id, $source, $quantity, $uom,
            SettlementRule::forCurrency($this->usd, RoundingMode::HALF_UP));
    }

    private function price(string $id = 'P1', int $rank = 10, string $unit = '120.00'): PriceCandidate
    {
        return new PriceCandidate($id, $rank, $unit, $this->usd->id);
    }

    private function resolve(PricingInput $input, PriceCandidate ...$candidates): PricingResult
    {
        return app(ResolveSalesPrice::class)($input, array_values($candidates ?: [$this->price()]));
    }

    private function refusal(callable $call): string
    {
        try {
            $call();
        } catch (DomainInvariantException $refusal) {
            return (string) $refusal->reasonCode;
        }
        $this->fail('Expected a refusal.');
    }

    private function stockedItem(string $code, ?string $avgCost, ItemType $type = ItemType::Raw): Item
    {
        $item = Item::create(['code' => $code, 'name' => $code, 'type' => $type, 'stock_uom_id' => $this->kg->id, 'category_id' => $this->category->id, 'is_sellable' => true, 'is_stocked' => true]);
        if ($avgCost !== null) {
            AvcoPool::create(['item_id' => $item->id, 'scope' => AvcoPoolScope::CompanyItem, 'qty_on_hand' => '100', 'inventory_value' => bcmul('100', $avgCost, 4),
                'avg_unit_cost' => $avgCost, 'base_avg_unit_cost' => $avgCost, 'last_valid_avg_unit_cost' => $avgCost, 'status' => AvcoPoolStatus::Active]);
        }

        return $item;
    }

    /** @return array<string, int|float> */
    private function stockAndLedgerCounts(): array
    {
        return ['moves' => DB::table('stock_moves')->count(), 'layers' => DB::table('stock_layers')->count(), 'entries' => DB::table('stock_valuation_entries')->count(),
            'pool_value' => (float) DB::table('avco_pools')->sum('inventory_value')];
    }

    public function test_direct_item_prices_the_line_exactly_and_carries_complete_stock_cost_for_a_prospect_without_an_account(): void
    {
        $item = $this->stockedItem('PIGMENT', '10.0000');
        $this->assertNull($this->prospect->customerAccount);
        $before = $this->stockAndLedgerCounts();

        $result = $this->resolve($this->input(PricingSource::directItem($item->id), '3', $this->kg->id), $this->price('LIST', 10, '0.3350'));

        $this->assertSame('1.0050', (string) $result->line->computedDecimal());
        $this->assertSame('1.0100', (string) $result->line->settled);
        $this->assertSame(Certainty::Complete, $result->cost->certainty);
        $this->assertSame('30.0000', (string) $result->cost->amount); // 3 kg × 10.0000, base currency
        $this->assertSame($this->usd->id, $result->cost->currencyId);
        $this->assertSame($before, $this->stockAndLedgerCounts(), 'The kernel reads cost and posts nothing.');
    }

    public function test_a_valid_zero_price_is_explicit_and_a_missing_price_is_refused(): void
    {
        $item = $this->stockedItem('FREEBIE', '1.0000');
        $input = $this->input(PricingSource::directItem($item->id), '5', $this->kg->id);

        $zero = $this->resolve($input, $this->price('ZERO', 10, '0'));
        $this->assertSame('0.0000', (string) $zero->line->settled);
        $this->assertSame('NO_ELIGIBLE_PRICE', $this->refusal(fn () => app(ResolveSalesPrice::class)($input, [])));
    }

    public function test_unknown_cost_is_unknown_not_zero_and_never_changes_the_price(): void
    {
        $item = $this->stockedItem('NOPOOL', null);

        $result = $this->resolve($this->input(PricingSource::directItem($item->id), '2', $this->kg->id), $this->price('P', 10, '50.00'));

        $this->assertSame(Certainty::Unknown, $result->cost->certainty);
        $this->assertNull($result->cost->amount);
        $this->assertNull($result->cost->knownAmount);
        $this->assertSame('100.0000', (string) $result->line->settled);
    }

    public function test_service_item_cost_is_explicitly_unknown_and_wrong_declared_kind_is_incompatible(): void
    {
        $service = Item::create(['code' => 'SURVEY', 'name' => 'Survey', 'type' => ItemType::Service, 'stock_uom_id' => $this->hour->id, 'category_id' => $this->category->id, 'is_sellable' => true, 'is_stocked' => false]);

        $result = $this->resolve($this->input(PricingSource::serviceItem($service->id), '4', $this->hour->id), $this->price('S', 10, '75.00'));
        $this->assertSame('300.0000', (string) $result->line->settled);
        $this->assertSame(Certainty::Unknown, $result->cost->certainty);
        $this->assertSame('SERVICE_COST_PROVIDER_UNSUPPORTED', $result->cost->reasons[0]['code']);

        $this->assertSame('SOURCE_INCOMPATIBLE', $this->refusal(fn () => $this->resolve($this->input(PricingSource::directItem($service->id), '1', $this->hour->id))));
        $goods = $this->stockedItem('GOODS', '1.0000');
        $this->assertSame('SOURCE_INCOMPATIBLE', $this->refusal(fn () => $this->resolve($this->input(PricingSource::serviceItem($goods->id), '1', $this->kg->id))));
    }

    public function test_source_refusals_missing_archived_unsellable_unit_and_foreign_company(): void
    {
        $item = $this->stockedItem('GOODS', '1.0000');
        $this->assertSame('SOURCE_NOT_FOUND', $this->refusal(fn () => $this->resolve($this->input(PricingSource::directItem(999999), '1', $this->kg->id))));
        $this->assertSame('SELLING_UNIT_UNSUPPORTED', $this->refusal(fn () => $this->resolve($this->input(PricingSource::directItem($item->id), '1', $this->hour->id))));
        $this->assertSame('SELLING_UNIT_UNSUPPORTED', $this->refusal(fn () => $this->resolve($this->input(PricingSource::directItem($item->id), '1', null))));

        $item->update(['is_sellable' => false]);
        $this->assertSame('SOURCE_INCOMPATIBLE', $this->refusal(fn () => $this->resolve($this->input(PricingSource::directItem($item->id), '1', $this->kg->id))));
        $item->update(['is_sellable' => true, 'status' => 'ARCHIVED']);
        $this->assertSame('SOURCE_INCOMPATIBLE', $this->refusal(fn () => $this->resolve($this->input(PricingSource::directItem($item->id), '1', $this->kg->id))));
    }

    public function test_another_companys_item_and_party_are_not_found(): void
    {
        $item = $this->stockedItem('MINE', '1.0000');
        $input = $this->input(PricingSource::directItem($item->id), '1', $this->kg->id);
        $otherCompanyId = DB::table('companies')->insertGetId(['name' => 'Other Co', 'created_at' => now(), 'updated_at' => now()]);
        $this->app->instance(CompanyContext::class, new class($otherCompanyId) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });

        // The party is the first thing checked, and neither it nor the item exists in the other company.
        $this->assertSame('PARTY_NOT_FOUND', $this->refusal(fn () => app(ResolveSalesPrice::class)($input, [$this->price()])));
        // The actor context itself refuses a user from outside the active company.
        $this->assertSame('ACTOR_OUTSIDE_COMPANY', $this->refusal(fn () => SalesActorContext::forUser($this->user, 'test')));
    }

    public function test_unsupported_input_is_refused(): void
    {
        $item = $this->stockedItem('GOODS', '1.0000');
        $this->assertSame('QUANTITY_ZERO', $this->refusal(fn () => $this->resolve($this->input(PricingSource::directItem($item->id), '0', $this->kg->id))));
        $this->assertSame('QUANTITY_NEGATIVE', $this->refusal(fn () => $this->resolve($this->input(PricingSource::directItem($item->id), '-1', $this->kg->id))));
        $this->assertSame('QUANTITY_NOT_REPRESENTABLE', $this->refusal(fn () => $this->resolve($this->input(PricingSource::directItem($item->id), '0.0000000001', $this->kg->id))));
        $this->assertSame('INVALID_INPUT', $this->refusal(fn () => PricingSource::manual(' ', 'sum')));
        $this->assertSame('PARTY_NOT_FOUND', $this->refusal(fn () => $this->resolve($this->input(PricingSource::directItem($item->id), '1', $this->kg->id, party: 999999))));
        $this->prospect->update(['status' => 'ARCHIVED']);
        $this->assertSame('PARTY_NOT_FOUND', $this->refusal(fn () => $this->resolve($this->input(PricingSource::directItem($item->id), '1', $this->kg->id))));
    }

    /** PX1 (ADR 0142): each source applies its own quantity contract, before any cost is read, with the shared stable refusal codes. */
    public function test_a_stock_item_quantity_is_held_to_four_places_and_the_items_own_increment(): void
    {
        $item = $this->stockedItem('CEMENT', '2.0000');
        $line = fn (string $quantity) => $this->resolve($this->input(PricingSource::directItem($item->id), $quantity, $this->kg->id), $this->price('L', 10, '3.00'));

        $this->assertSame('1.2750', (string) $line('1.275')->quantity);
        $this->assertSame('3.8250', (string) $line('1.275')->line->computedDecimal());
        $this->assertSame('QUANTITY_NOT_REPRESENTABLE', $this->refusal(fn () => $line('1.27501')), 'Five places do not fit the four-place ledger and are never rounded.');
        $this->assertSame('QUANTITY_NOT_REPRESENTABLE', $this->refusal(fn () => $line('0.000000001')), 'The old nine-place allowance is gone for stock.');
        $this->assertSame('QUANTITY_OVERFLOW', $this->refusal(fn () => $line('1000000000000000')));

        $item->update(['quantity_precision' => '0.5']);
        $this->assertSame('2.5000', (string) $line('2.5')->quantity);
        $this->assertSame('QUANTITY_INCREMENT_VIOLATED', $this->refusal(fn () => $line('1.25')));
        $this->assertSame('QUANTITY_INCREMENT_VIOLATED', $this->refusal(fn () => $line('0.25')));
    }

    public function test_unsupported_quantity_values_are_refused_as_invalid_never_coerced(): void
    {
        $item = $this->stockedItem('GOODS', '1.0000');
        foreach (['abc', '', '1e3', '1,5', ' 1'] as $bad) {
            $this->assertSame('QUANTITY_INVALID', $this->refusal(fn () => $this->resolve($this->input(PricingSource::directItem($item->id), $bad, $this->kg->id))), "'{$bad}'");
        }
        foreach ([1.5, null, [], true] as $bad) {
            $input = new PricingInput(SalesActorContext::forUser($this->user, 'test'), $this->prospect->id, PricingSource::directItem($item->id), $bad, $this->kg->id,
                SettlementRule::forCurrency($this->usd, RoundingMode::HALF_UP));
            $this->assertSame('QUANTITY_INVALID', $this->refusal(fn () => $this->resolve($input)));
        }
    }

    public function test_a_service_item_uses_the_same_four_place_and_increment_rule(): void
    {
        $service = Item::create(['code' => 'INSPECT', 'name' => 'Inspection', 'type' => ItemType::Service, 'stock_uom_id' => $this->hour->id, 'category_id' => $this->category->id, 'is_sellable' => true, 'quantity_precision' => '0.25']);
        $line = fn (string $quantity) => $this->resolve($this->input(PricingSource::serviceItem($service->id), $quantity, $this->hour->id), $this->price('S', 10, '80.00'));

        $this->assertSame('1.5000', (string) $line('1.5')->quantity);
        $this->assertSame('120.0000', (string) $line('1.5')->line->settled);
        $this->assertSame('QUANTITY_INCREMENT_VIOLATED', $this->refusal(fn () => $line('1.1')));
        $this->assertSame('QUANTITY_NOT_REPRESENTABLE', $this->refusal(fn () => $line('1.00001')));
    }

    public function test_assembly_work_keeps_six_technical_places_and_refuses_a_seventh(): void
    {
        $pigment = $this->stockedItem('PIGMENT', '10.0000');
        $version = $this->activatedVersion([['kind' => 'MATERIAL', 'item_id' => $pigment->id, 'qty_per_work_unit' => '1.000000', 'uom_id' => $this->kg->id]]);
        $costActor = PermissionedActor::grant(User::factory()->create(), ['estimating.internal_cost.view']);
        $line = fn (string $quantity, ?User $actor = null) => $this->resolve($this->input(PricingSource::assemblyVersion($version->id), $quantity, $this->sqm->id, $actor ?? $costActor), $this->price('A', 10, '100.00'));

        $result = $line('0.000125');
        $this->assertSame('0.000125', (string) $result->quantity, 'A legitimate six-place technical quantity is preserved, not cut to four.');
        $this->assertSame('0.0125', (string) $result->line->computedDecimal());
        $this->assertSame('QUANTITY_NOT_REPRESENTABLE', $this->refusal(fn () => $line('0.0000001')));
        $this->assertSame('QUANTITY_ZERO', $this->refusal(fn () => $line('0.000000')));
        // The quantity is proven before the cost duty is exercised, so a bad quantity is reported as such rather than as a cost refusal.
        $this->assertSame('QUANTITY_NOT_REPRESENTABLE', $this->refusal(fn () => $line('0.0000001', User::factory()->create())));
    }

    public function test_manual_scope_is_held_to_four_places(): void
    {
        $manual = fn (string $quantity) => $this->resolve($this->input(PricingSource::manual('Site clean-up', 'lump'), $quantity, null), $this->price('M', 10, '10.00'));

        $this->assertSame('2.5000', (string) $manual('2.5')->quantity);
        $this->assertSame('QUANTITY_NOT_REPRESENTABLE', $this->refusal(fn () => $manual('2.00001')));
        $this->assertSame('QUANTITY_NOT_REPRESENTABLE', $this->refusal(fn () => $manual('0.000001')), 'Six places are a technical scale, not a manual selling quantity.');
        $this->assertSame('QUANTITY_NEGATIVE', $this->refusal(fn () => $manual('-1')));
    }

    public function test_a_refused_quantity_reads_no_cost_and_posts_nothing(): void
    {
        $item = $this->stockedItem('GOODS', '1.0000');
        $before = $this->stockAndLedgerCounts();
        $this->assertSame('QUANTITY_NOT_REPRESENTABLE', $this->refusal(fn () => $this->resolve($this->input(PricingSource::directItem($item->id), '1.00001', $this->kg->id))));
        $this->assertSame($before, $this->stockAndLedgerCounts());
    }

    public function test_equal_ranked_candidates_conflict_lower_rank_wins_and_the_cheapest_is_never_chosen(): void
    {
        $item = $this->stockedItem('GOODS', '1.0000');
        $input = $this->input(PricingSource::directItem($item->id), '1', $this->kg->id);

        $this->assertSame('PRICING_RULE_CONFLICT', $this->refusal(fn () => $this->resolve($input, $this->price('A', 20, '900'), $this->price('B', 20, '650'))));
        $winner = $this->resolve($input, $this->price('LOW', 30, '10'), $this->price('HIGH', 20, '900'));
        $this->assertSame('HIGH', $winner->price->id);
        $this->assertSame('900.0000', (string) $winner->line->settled);
        $this->assertSame('INVALID_INPUT', $this->refusal(fn () => $this->resolve($input, $this->price('A', 20), $this->price('A', 30))));
        $this->assertSame('PRICE_BASIS_INCOMPATIBLE', $this->refusal(fn () => $this->resolve($input, new PriceCandidate('EUR', 10, '1', $this->usd->id + 99))));
    }

    public function test_manual_scope_needs_no_catalogue_and_defaults_to_unknown_cost(): void
    {
        $unknown = $this->resolve($this->input(PricingSource::manual('Site clean-up', 'lump'), '1', null), $this->price('M', 10, '2500.00'));
        $this->assertSame('2500.0000', (string) $unknown->line->settled);
        $this->assertSame('MANUAL_COST_NOT_PROVIDED', $unknown->cost->reasons[0]['code']);
        $this->assertNull($unknown->source->sourceId);

        $declared = $this->resolve($this->input(PricingSource::manual('Skip hire', 'each', FinancialResult::partial('100', $this->usd->id, [['code' => 'HAULAGE_PENDING']])), '1', null));
        $this->assertSame(Certainty::Partial, $declared->cost->certainty);
        $this->assertNull($declared->cost->amount);
    }

    public function test_assembly_work_costs_through_the_estimating_rollup_and_needs_the_cost_duty(): void
    {
        $pigment = $this->stockedItem('PIGMENT', '10.0000');
        $electrician = Item::create(['code' => 'ELEC', 'name' => 'Electrician', 'type' => ItemType::Labour, 'stock_uom_id' => $this->hour->id, 'category_id' => $this->category->id]);
        ResourceRate::create(['item_id' => $electrician->id, 'rate' => '20.000000', 'rate_uom_id' => $this->hour->id, 'effective_from' => '2026-01-01']);
        $version = $this->activatedVersion([
            ['kind' => 'MATERIAL', 'item_id' => $pigment->id, 'qty_per_work_unit' => '0.500000', 'uom_id' => $this->kg->id, 'wastage_pct' => '5.0000', 'multiplier' => '2.0000'],
            ['kind' => 'LABOUR', 'item_id' => $electrician->id, 'qty_per_work_unit' => '1.500000', 'uom_id' => $this->hour->id],
        ]);
        $costActor = PermissionedActor::grant(User::factory()->create(), ['estimating.internal_cost.view']);

        $result = $this->resolve($this->input(PricingSource::assemblyVersion($version->id), '2', $this->sqm->id, $costActor), $this->price('A', 10, '100.00'));

        // Per sqm: 0.5 × 1.05 × 2 = 1.05 kg × 10 = 10.50 plus 1.5 h × 20 = 30.00 → 40.50; for 2 sqm 81.00. Price 2 × 100.00.
        $this->assertSame('200.0000', (string) $result->line->settled);
        $this->assertSame(Certainty::Complete, $result->cost->certainty);
        $this->assertSame('81.0000', (string) $result->cost->amount);

        $this->assertSame('SOURCE_EVIDENCE_NOT_AUTHORISED', $this->refusal(fn () => $this->resolve($this->input(PricingSource::assemblyVersion($version->id), '2', $this->sqm->id))));
        $this->assertSame('SELLING_UNIT_UNSUPPORTED', $this->refusal(fn () => $this->resolve($this->input(PricingSource::assemblyVersion($version->id), '2', $this->kg->id, $costActor))));
        $this->assertSame('SOURCE_NOT_FOUND', $this->refusal(fn () => $this->resolve($this->input(PricingSource::assemblyVersion(999999), '2', $this->sqm->id, $costActor))));
    }

    public function test_assembly_with_a_component_that_has_no_rate_is_partial_never_complete(): void
    {
        $pigment = $this->stockedItem('PIGMENT', '10.0000');
        $unrated = Item::create(['code' => 'CRANE', 'name' => 'Crane', 'type' => ItemType::Plant, 'stock_uom_id' => $this->hour->id, 'category_id' => $this->category->id]);
        $version = $this->activatedVersion([
            ['kind' => 'MATERIAL', 'item_id' => $pigment->id, 'qty_per_work_unit' => '1.000000', 'uom_id' => $this->kg->id],
            ['kind' => 'PLANT', 'item_id' => $unrated->id, 'qty_per_work_unit' => '1.000000', 'uom_id' => $this->hour->id],
        ]);
        $costActor = PermissionedActor::grant(User::factory()->create(), ['estimating.internal_cost.view']);

        $result = $this->resolve($this->input(PricingSource::assemblyVersion($version->id), '1', $this->sqm->id, $costActor));

        $this->assertSame(Certainty::Partial, $result->cost->certainty);
        $this->assertNull($result->cost->amount);
        $this->assertSame('10.0000', (string) $result->cost->knownAmount);
    }

    public function test_a_draft_assembly_version_is_incompatible(): void
    {
        $assembly = Assembly::create(['code' => 'ASM-D', 'name' => 'Draft', 'work_uom_id' => $this->sqm->id, 'margin_type' => 'MARKUP', 'margin_pct' => '0']);
        $draft = $assembly->versions()->create(['version_no' => 1])->refresh();
        $costActor = PermissionedActor::grant(User::factory()->create(), ['estimating.internal_cost.view']);

        $this->assertSame('SOURCE_INCOMPATIBLE', $this->refusal(fn () => $this->resolve($this->input(PricingSource::assemblyVersion($draft->id), '1', $this->sqm->id, $costActor))));
    }

    public function test_cost_is_omitted_not_zeroed_for_a_reader_who_may_not_see_it(): void
    {
        $item = $this->stockedItem('GOODS', '10.0000');
        $result = $this->resolve($this->input(PricingSource::directItem($item->id), '1', $this->kg->id));

        $blind = $result->forReader(false);

        $this->assertNull($blind->cost);
        $this->assertTrue($blind->costWithheld);
        $this->assertSame(Certainty::Unknown, $blind->source->cost->certainty);
        $this->assertSame($result->line->settled, $blind->line->settled);
        $this->assertSame($result, $result->forReader(true));
    }

    public function test_an_item_that_exists_only_in_another_company_is_not_found_even_for_a_party_that_does_too(): void
    {
        $otherCompanyId = DB::table('companies')->insertGetId(['name' => 'Other Co', 'created_at' => now(), 'updated_at' => now()]);
        $mine = app(CompanyContext::class);
        $this->app->instance(CompanyContext::class, new class($otherCompanyId) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });
        $foreignCategory = Category::create(['code' => 'RAW', 'name' => 'Raw', 'default_valuation_method' => 'AVCO']);
        $foreignKg = Uom::create(['code' => 'kg', 'name' => 'Kilogram', 'dimension' => UomDimension::Mass, 'factor_to_base' => '1', 'is_base' => true]);
        $foreignItem = Item::create(['code' => 'THEIRS', 'name' => 'Theirs', 'type' => ItemType::Raw, 'stock_uom_id' => $foreignKg->id, 'category_id' => $foreignCategory->id, 'is_sellable' => true, 'is_stocked' => true]);
        $this->app->instance(CompanyContext::class, $mine);

        $this->assertSame('SOURCE_NOT_FOUND', $this->refusal(fn () => $this->resolve($this->input(PricingSource::directItem($foreignItem->id), '1', $this->kg->id))));
    }

    public function test_the_intent_hash_is_the_existing_canonical_crm_convention(): void
    {
        $a = OperationIntent::hash('price', ['b' => 1, 'a' => ['y' => 2, 'x' => 1]]);
        $b = OperationIntent::hash('price', ['a' => ['x' => 1, 'y' => 2], 'b' => 1]);

        $this->assertSame($a, $b);
        $this->assertNotSame($a, OperationIntent::hash('price', ['a' => ['x' => 1, 'y' => 3], 'b' => 1]));
        $this->assertNotSame($a, OperationIntent::hash('other', ['a' => ['x' => 1, 'y' => 2], 'b' => 1]));
    }

    /** @param list<array<string, mixed>> $components */
    private function activatedVersion(array $components): AssemblyVersion
    {
        $assembly = Assembly::create(['code' => 'ASM-'.str()->random(6), 'name' => 'Test', 'work_uom_id' => $this->sqm->id, 'margin_type' => 'MARKUP', 'margin_pct' => '0']);
        $version = $assembly->versions()->create(['version_no' => 1])->refresh();
        foreach ($components as $sequence => $component) {
            $version->components()->create([...$component, 'sequence' => $sequence]);
        }
        (new ActivateAssemblyVersion)($version, PermissionedActor::grant(User::factory()->create(), ['estimating.assembly.manage']));

        return $version->refresh();
    }
}
