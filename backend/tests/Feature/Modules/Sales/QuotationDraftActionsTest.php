<?php

namespace Tests\Feature\Modules\Sales;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Estimating\Models\Assembly;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\ItemType;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Actions\ActivateRoundingPolicy;
use App\Modules\Sales\Actions\CreateQuotationDraft;
use App\Modules\Sales\Actions\CreateRoundingPolicyDraft;
use App\Modules\Sales\Actions\PreviewQuotation;
use App\Modules\Sales\Actions\ReadQuotationDraft;
use App\Modules\Sales\Actions\UpdateQuotationDraft;
use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Models\QuotationFamily;
use App\Modules\Sales\Models\QuotationOperationReceipt;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Models\SalesStage;
use App\Modules\Sales\Support\Authority\DutyStatus;
use App\Modules\Sales\Support\Authority\SalesDuty;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\Financial\Policy\SettlementRounding;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\QuotationDraftOutcome;
use App\Support\PermissionRegistry;
use App\Support\RateType;
use App\Support\UomDimension;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * QL1a — the quotation draft Actions called directly (no route exists): staged duties, tenant isolation, optimistic concurrency, receipts, entered money with
 * its dual-money evidence, derived measures, zero versus unknown, and hand-derived settlement arithmetic.
 */
class QuotationDraftActionsTest extends TestCase
{
    use RefreshDatabase;

    private const PREPARE = PermissionVocabulary::QUOTATION_PREPARE;

    private const VIEW = PermissionVocabulary::QUOTATION_VIEW;

    private User $preparer;

    private Currency $pkr;

    private Currency $usd;

    private Uom $kg;

    private Uom $hour;

    private Item $pigment;

    private Party $prospect;

    private string $today;

    private int $keys = 0;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        foreach ([self::PREPARE, self::VIEW] as $duty) {
            Permission::findOrCreate($duty, 'web');
        }
        $this->preparer = User::factory()->create();
        $this->preparer->givePermissionTo([self::PREPARE, self::VIEW]);

        $this->pkr = Currency::firstOrCreate(['code' => 'PKR'], ['name' => 'Rupee', 'symbol' => 'Rs', 'decimal_places' => 2]);
        $this->usd = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'Dollar', 'symbol' => '$', 'decimal_places' => 2]);
        Company::findOrFail(1)->update(['base_currency_id' => $this->pkr->id]);
        $this->today = now(Company::findOrFail(1)->timezone)->format('Y-m-d');
        $this->kg = Uom::create(['code' => 'kg', 'name' => 'Kilogram', 'dimension' => UomDimension::Mass, 'factor_to_base' => '1', 'is_base' => true]);
        $this->hour = Uom::create(['code' => 'hr', 'name' => 'Hour', 'dimension' => UomDimension::Time, 'factor_to_base' => '1', 'is_base' => true]);
        $category = Category::create(['code' => 'RAW', 'name' => 'Raw', 'default_valuation_method' => 'AVCO']);
        $this->pigment = Item::create(['code' => 'PIGMENT', 'name' => 'Pigment', 'type' => ItemType::Raw, 'stock_uom_id' => $this->kg->id, 'category_id' => $category->id, 'is_sellable' => true, 'is_stocked' => true]);
        $this->prospect = Party::create(['code' => 'PROSPECT', 'name' => 'Prospect Ltd']);
        $this->enableDuties();
    }

    /** The shipped manifest with the two staged quotation duties switched on, to test Actions the shipped manifest keeps disabled. */
    private function enableDuties(): void
    {
        $duties = array_map(fn (SalesDuty $duty): SalesDuty => in_array($duty->name, [self::PREPARE, self::VIEW, PermissionVocabulary::FINANCIAL_CONFIGURE], true)
            ? new SalesDuty($duty->name, $duty->class, DutyStatus::Active, $duty->operation, $duty->projection, $duty->deliveryGate) : $duty, SalesDutyManifest::shipped()->all());
        $this->app->instance(SalesDutyManifest::class, new SalesDutyManifest($duties));
    }

    private function key(): string
    {
        return 'key-'.++$this->keys;
    }

    private function create(array $input = [], ?User $by = null, ?string $key = null): QuotationDraftOutcome
    {
        return app(CreateQuotationDraft::class)($by ?? $this->preparer, $key ?? $this->key(), $input + ['recipient_party_id' => $this->prospect->id, 'pricing_date' => $this->today]);
    }

    private function update(QuotationDraftOutcome|int $draft, array $input, ?int $generation = null, ?string $key = null, ?User $by = null): QuotationDraftOutcome
    {
        $revisionId = $draft instanceof QuotationDraftOutcome ? $draft->revisionId : $draft;
        $generation ??= QuotationRevision::findOrFail($revisionId)->input_generation;

        return app(UpdateQuotationDraft::class)($by ?? $this->preparer, $revisionId, $generation, $key ?? $this->key(), $input);
    }

    private function preview(QuotationDraftOutcome|int $draft, ?User $by = null): array
    {
        return app(PreviewQuotation::class)($by ?? $this->preparer, $draft instanceof QuotationDraftOutcome ? $draft->revisionId : $draft);
    }

    private function manual(string $description, string $quantity, ?string $price, array $more = []): array
    {
        return ['source_kind' => 'MANUAL', 'description' => $description, 'manual_measure' => 'job', 'offered_quantity' => $quantity, 'unit_price' => $price] + $more;
    }

    private function rate(Currency $from, string $rate, ?string $date = null): ExchangeRate
    {
        return ExchangeRate::create(['from_currency_id' => $from->id, 'to_currency_id' => $this->pkr->id, 'rate' => $rate, 'rate_date' => $date ?? $this->today, 'rate_type' => RateType::Spot, 'source' => 'manual']);
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

    // ---- authority -------------------------------------------------------------------------------------------------------------------------------------

    public function test_the_duties_are_staged_so_every_action_refuses_everyone_including_a_raw_grant(): void
    {
        $this->app->instance(SalesDutyManifest::class, SalesDutyManifest::shipped());
        $this->assertTrue($this->preparer->fresh()->can(self::PREPARE), 'The raw permission row is a grant in the store…');

        foreach ([
            fn () => app(CreateQuotationDraft::class)($this->preparer, 'k1', []),
            fn () => app(UpdateQuotationDraft::class)($this->preparer, 1, 1, 'k2', ['description' => 'x']),
            fn () => app(PreviewQuotation::class)($this->preparer, 1),
            fn () => app(ReadQuotationDraft::class)($this->preparer, 1),
        ] as $call) {
            $this->assertThrows($call, AuthorizationException::class);
        }
        $this->assertSame(0, QuotationFamily::query()->count());

        $registry = app(PermissionRegistry::class);
        $this->assertNotContains(self::PREPARE, $registry->all());
        $this->assertNotContains(self::VIEW, $registry->all());
        $this->assertNotContains(self::PREPARE, $registry->automaticGrants());
    }

    public function test_each_duty_is_judged_on_its_own_and_a_limited_scope_is_refused_by_the_direct_action(): void
    {
        $viewOnly = User::factory()->create();
        $viewOnly->givePermissionTo(self::VIEW);
        $prepareOnly = User::factory()->create();
        $prepareOnly->givePermissionTo(self::PREPARE);
        $limited = User::factory()->create();
        $limited->givePermissionTo(self::PREPARE);
        DB::table('access_scope_limits')->insert(['company_id' => 1, 'user_id' => $limited->id, 'permission' => self::PREPARE, 'scope' => 'MY_UNITS', 'created_at' => now(), 'updated_at' => now()]);
        $stranger = User::factory()->create();
        $draft = $this->create();

        foreach ([$viewOnly, $stranger, $limited->fresh()] as $who) {
            $this->assertThrows(fn () => $this->create(by: $who), AuthorizationException::class);
            $this->assertThrows(fn () => $this->update($draft, ['pricing_date' => $this->today], by: $who), AuthorizationException::class);
            $this->assertThrows(fn () => $this->preview($draft, $who), AuthorizationException::class);
        }
        $this->assertThrows(fn () => app(ReadQuotationDraft::class)($prepareOnly, $draft->revisionId), AuthorizationException::class);
        $this->assertThrows(fn () => app(ReadQuotationDraft::class)($stranger, $draft->revisionId), AuthorizationException::class);
        $this->assertSame($draft->revisionId, app(ReadQuotationDraft::class)($viewOnly, $draft->revisionId)['revision_id']);
        $this->assertSame(1, QuotationFamily::query()->count(), 'Nothing was created by a refused caller.');
    }

    public function test_view_returns_safe_metadata_only(): void
    {
        $draft = $this->create();
        $this->update($draft, ['add_lines' => [$this->manual('Floor coat', '3', '0.3350')]]);

        $summary = app(ReadQuotationDraft::class)($this->preparer, $draft->revisionId);

        $this->assertSame(1, $summary['line_count']);
        $this->assertSame('DRAFT', $summary['state']);
        $json = json_encode($summary);
        foreach (['price', 'amount', 'net', 'cost', '0.3350'] as $figure) {
            $this->assertStringNotContainsString($figure, $json);
        }
    }

    // ---- tenancy ---------------------------------------------------------------------------------------------------------------------------------------

    public function test_another_companys_quotation_party_and_opportunity_are_simply_not_found(): void
    {
        $other = DB::table('companies')->insertGetId(['name' => 'Other Co', 'created_at' => now(), 'updated_at' => now()]);
        $otherUser = DB::table('users')->insertGetId(['company_id' => $other, 'name' => 'O', 'email' => 'o@other.test', 'password' => 'x', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        $family = DB::table('quotation_families')->insertGetId(['company_id' => $other, 'created_by' => $otherUser, 'created_at' => now(), 'updated_at' => now()]);
        $revision = DB::table('quotation_revisions')->insertGetId(['company_id' => $other, 'family_id' => $family, 'currency_id' => $this->pkr->id, 'created_by' => $otherUser, 'created_at' => now(), 'updated_at' => now()]);
        $foreignParty = DB::table('parties')->insertGetId(['company_id' => $other, 'code' => 'F', 'name' => 'Foreign', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame('QUOTATION_NOT_FOUND', $this->refusal(fn () => $this->update($revision, ['pricing_date' => $this->today], 1)));
        $this->assertSame('QUOTATION_NOT_FOUND', $this->refusal(fn () => $this->preview($revision)));
        $this->assertSame('QUOTATION_NOT_FOUND', $this->refusal(fn () => app(ReadQuotationDraft::class)($this->preparer, $revision)));
        $this->assertSame('INVALID_INPUT', $this->refusal(fn () => $this->create(['recipient_party_id' => $foreignParty])));
        Permission::findOrCreate(PermissionVocabulary::PIPELINE_VIEW, 'web');
        $this->preparer->givePermissionTo(PermissionVocabulary::PIPELINE_VIEW);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->preparer = $this->preparer->fresh();
        $foreignOpportunity = DB::table('sales_opportunities')->insertGetId(['company_id' => $other, 'title' => 'Foreign', 'party_id' => $foreignParty, 'owner_id' => $otherUser,
            'stage_id' => DB::table('sales_stages')->insertGetId(['company_id' => $other, 'code' => 'X', 'name' => 'X', 'sort_order' => 1, 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]),
            'created_by' => $otherUser, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('OPPORTUNITY_NOT_FOUND', $this->refusal(fn () => $this->create(['opportunity_id' => $foreignOpportunity])));
        $this->assertSame('OPPORTUNITY_NOT_FOUND', $this->refusal(fn () => $this->create(['opportunity_id' => 999999])));
        $this->assertSame(1, DB::table('quotation_revisions')->where('company_id', $other)->count());
        $this->assertSame(1, DB::table('quotation_revisions')->where('id', $revision)->value('input_generation'), 'The foreign revision was untouched.');
    }

    // ---- create, receipts ------------------------------------------------------------------------------------------------------------------------------

    public function test_create_defaults_to_the_base_currency_allocates_no_number_and_links_a_readable_opportunity(): void
    {
        $stage = SalesStage::create(['code' => 'Q', 'name' => 'Qualification', 'sort_order' => 10, 'status' => 'ACTIVE'])->id;
        $opportunity = DB::table('sales_opportunities')->insertGetId(['company_id' => 1, 'title' => 'Warehouse', 'party_id' => $this->prospect->id, 'owner_id' => $this->preparer->id,
            'stage_id' => $stage, 'created_by' => $this->preparer->id, 'created_at' => now(), 'updated_at' => now()]);
        Permission::findOrCreate(PermissionVocabulary::PIPELINE_VIEW, 'web');
        $before = DB::table('numbering_allocations')->count();

        // Preparing is not enough to link an Opportunity: an independent legitimate read of the pipeline is also needed.
        $this->assertThrows(fn () => $this->create(['opportunity_id' => $opportunity]), AuthorizationException::class);
        $this->preparer->givePermissionTo(PermissionVocabulary::PIPELINE_VIEW);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->preparer = $this->preparer->fresh();
        $draft = $this->create(['opportunity_id' => $opportunity]);

        $revision = QuotationRevision::findOrFail($draft->revisionId);
        $this->assertSame($this->pkr->id, $revision->currency_id);
        $this->assertSame([1, 1], [$revision->revision_ordinal, $revision->input_generation]);
        $family = QuotationFamily::findOrFail($draft->familyId);
        $this->assertSame([$opportunity, 1], [$family->current_opportunity_id, $family->association_generation]);
        $this->assertSame(1, $family->associationEvents()->count());
        $this->assertSame($before, DB::table('numbering_allocations')->count(), 'Creating a draft allocates no number.');
    }

    public function test_an_opportunity_must_be_open_and_share_the_recipient_party(): void
    {
        $stage = SalesStage::create(['code' => 'Q', 'name' => 'Qualification', 'sort_order' => 10, 'status' => 'ACTIVE'])->id;
        Permission::findOrCreate(PermissionVocabulary::PIPELINE_VIEW, 'web');
        $this->preparer->givePermissionTo(PermissionVocabulary::PIPELINE_VIEW);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->preparer = $this->preparer->fresh();
        $other = Party::create(['code' => 'OTHER', 'name' => 'Other Ltd']);
        $deal = fn (int $party) => DB::table('sales_opportunities')->insertGetId(['company_id' => 1, 'title' => 'Deal', 'party_id' => $party, 'owner_id' => $this->preparer->id,
            'stage_id' => $stage, 'created_by' => $this->preparer->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame('OPPORTUNITY_PARTY_MISMATCH', $this->refusal(fn () => $this->create(['opportunity_id' => $deal($other->id)])));
        $this->assertSame(0, QuotationFamily::query()->count());

        // No recipient yet is allowed; the rule applies once both Parties are known.
        $withParty = $deal($this->prospect->id);
        $draft = $this->create(['opportunity_id' => $withParty, 'recipient_party_id' => null]);
        $this->assertSame('OPPORTUNITY_PARTY_MISMATCH', $this->refusal(fn () => $this->update($draft, ['recipient_party_id' => $other->id])));
        $this->update($draft, ['recipient_party_id' => $this->prospect->id]);
        $this->assertSame($this->prospect->id, QuotationRevision::findOrFail($draft->revisionId)->recipient_party_id);

        $closed = $deal($this->prospect->id);
        DB::table('sales_opportunities')->where('id', $closed)->update(['outcome' => 'LOST', 'closed_at' => now(), 'closed_by' => $this->preparer->id, 'closure_reason' => 'Lost']);
        $this->assertSame('OPPORTUNITY_NOT_OPEN', $this->refusal(fn () => $this->create(['opportunity_id' => $closed])));
    }

    public function test_the_same_key_replays_and_a_different_request_under_it_is_refused(): void
    {
        $first = $this->create([], key: 'once');
        $replay = $this->create([], key: 'once');

        $this->assertFalse($first->replayed);
        $this->assertTrue($replay->replayed);
        $this->assertSame($first->revisionId, $replay->revisionId);
        $this->assertSame(1, QuotationFamily::query()->count());
        $this->assertSame(1, QuotationOperationReceipt::query()->count());
        $this->assertSame('REQUEST_KEY_REUSED', $this->refusal(fn () => $this->create(['valid_through_local_date' => '2027-01-31'], key: 'once')));
        $this->assertSame('REQUEST_KEY_REQUIRED', $this->refusal(fn () => app(CreateQuotationDraft::class)($this->preparer, '  ', [])));
        $this->assertSame('REQUEST_KEY_REQUIRED', $this->refusal(fn () => app(CreateQuotationDraft::class)($this->preparer, str_repeat('x', 86), [])));
        $this->assertSame(1, QuotationFamily::query()->count());
    }

    public function test_receipts_are_scoped_to_the_actor_and_append_only(): void
    {
        $other = User::factory()->create();
        $other->givePermissionTo(self::PREPARE);
        $mine = $this->create([], key: 'shared');
        $theirs = $this->create([], by: $other, key: 'shared');

        $this->assertNotSame($mine->revisionId, $theirs->revisionId, 'The same key from another actor is another request.');
        $receipt = QuotationOperationReceipt::query()->firstOrFail();
        $this->assertSame(['v' => 1, 'family_id' => $mine->familyId, 'revision_id' => $mine->revisionId, 'input_generation' => 1], $receipt->result);
        $this->assertDbRefuses(fn () => DB::table('sales_quotation_operation_receipts')->where('id', $receipt->id)->update(['operation' => 'x']));
        $this->assertDbRefuses(fn () => DB::table('sales_quotation_operation_receipts')->where('id', $receipt->id)->delete());
    }

    // ---- update: concurrency ---------------------------------------------------------------------------------------------------------------------------

    public function test_a_stale_generation_is_refused_and_a_completed_retry_replays_before_the_freshness_check(): void
    {
        $draft = $this->create();
        $first = $this->update($draft, ['add_lines' => [$this->manual('Coat', '1', '10.0000')]], 1, 'edit-1');

        $this->assertGreaterThan(1, $first->inputGeneration);
        // A second writer who still holds generation 1 is refused, and nothing of theirs is stored.
        $this->assertSame('QUOTATION_DRAFT_STALE', $this->refusal(fn () => $this->update($draft, ['add_lines' => [$this->manual('Lost', '1', '1')]], 1, 'edit-2')));
        $this->assertSame(1, QuotationDraftLine::query()->count());
        // The first writer's retry, after the draft moved on, replays the original result.
        $replay = $this->update($draft, ['add_lines' => [$this->manual('Coat', '1', '10.0000')]], 1, 'edit-1');
        $this->assertTrue($replay->replayed);
        $this->assertSame($first->inputGeneration, $replay->inputGeneration);
        $this->assertSame(1, QuotationDraftLine::query()->count(), 'The replay added nothing.');
        $this->assertSame('REQUEST_KEY_REUSED', $this->refusal(fn () => $this->update($draft, ['add_lines' => [$this->manual('Other', '1', '1')]], 1, 'edit-1')));
    }

    public function test_every_material_change_advances_the_generation_and_nothing_to_change_is_refused(): void
    {
        $draft = $this->create();
        $a = $this->update($draft, ['valid_through_local_date' => '2027-01-31']);
        $b = $this->update($draft, ['add_lines' => [$this->manual('Coat', '1', null)]]);

        $this->assertGreaterThan(1, $a->inputGeneration);
        $this->assertGreaterThan($a->inputGeneration, $b->inputGeneration);
        $this->assertSame('NOTHING_TO_CHANGE', $this->refusal(fn () => $this->update($draft, [])));
    }

    public function test_a_cancelled_draft_cannot_be_changed_or_previewed(): void
    {
        $draft = $this->create();
        DB::table('quotation_revisions')->where('id', $draft->revisionId)->update(['state' => 'CANCELLED_DRAFT', 'cancelled_at' => now(), 'cancelled_by' => $this->preparer->id, 'cancellation_reason' => 'Withdrawn']);

        $this->assertSame('QUOTATION_NOT_EDITABLE', $this->refusal(fn () => $this->update($draft, ['pricing_date' => $this->today], 1)));
        $this->assertSame('QUOTATION_NOT_EDITABLE', $this->refusal(fn () => $this->preview($draft)));
    }

    public function test_unknown_or_client_supplied_authority_fields_are_refused_not_ignored(): void
    {
        $draft = $this->create();

        foreach (['state' => 'ISSUED', 'approved' => true, 'tax_amount' => '1', 'created_by' => 1, 'input_generation' => 9] as $field => $value) {
            $this->assertSame('INVALID_INPUT', $this->refusal(fn () => $this->update($draft, [$field => $value])), $field);
        }
        foreach (['base_unit_price' => '1', 'fx_evidence' => [], 'cost' => '1', 'approved_price' => '5', 'status' => 'DONE', 'unit_cost' => '2'] as $field => $value) {
            $this->assertSame('INVALID_INPUT', $this->refusal(fn () => $this->update($draft, ['add_lines' => [$this->manual('x', '1', '1') + [$field => $value]]])), $field);
        }
        // A float has already lost what the user typed.
        $this->assertSame('INVALID_INPUT', $this->refusal(fn () => $this->update($draft, ['add_lines' => [array_replace($this->manual('x', '1', '1'), ['offered_quantity' => 1.5])]])));
        $this->assertSame('INVALID_INPUT', $this->refusal(fn () => $this->update($draft, ['add_lines' => [['source_kind' => 'MANUAL', 'description' => 'x', 'manual_measure' => 'job', 'offered_quantity' => '1', 'unit_price' => 0.1]]])));
        $this->assertSame(0, QuotationDraftLine::query()->count());
    }

    // ---- sources and measures --------------------------------------------------------------------------------------------------------------------------

    public function test_a_direct_item_line_derives_its_measures_from_source_resolution(): void
    {
        $draft = $this->create();
        $this->update($draft, ['add_lines' => [['source_kind' => 'DIRECT_ITEM', 'item_id' => $this->pigment->id, 'offered_quantity' => '12.5', 'unit_price' => '4.2000']]]);

        $line = QuotationDraftLine::query()->firstOrFail();
        $this->assertSame(strtolower($this->pigment->code).' — Pigment', $line->description, 'The description defaults to the proven source label.');
        $this->assertSame($this->kg->id, $line->offered_uom_id, 'The Item is sold in its own stock unit.');
        $this->assertSame([$this->kg->id, '12.5000', $this->kg->id, '12.5000', 1], [$line->priced_uom_id, $line->priced_quantity, $line->stock_uom_id, $line->stock_quantity, $line->conversion_version]);
        $this->assertNull(DB::table('quotation_draft_lines')->value('manual_measure'));
    }

    public function test_only_direct_item_service_item_and_manual_sources_are_authorised_and_each_is_proven(): void
    {
        $draft = $this->create();
        $service = Item::create(['code' => 'INSPECT', 'name' => 'Inspection', 'type' => ItemType::Service, 'stock_uom_id' => $this->hour->id, 'category_id' => $this->pigment->category_id, 'is_sellable' => true]);
        $line = fn (array $over) => $this->update($draft, ['add_lines' => [array_replace(['source_kind' => 'DIRECT_ITEM', 'item_id' => $this->pigment->id, 'offered_quantity' => '1'], $over)]]);

        // D1: a service Item is authorised, proven to be a service in its own unit, and its cost is never invented.
        $this->update($draft, ['add_lines' => [['source_kind' => 'SERVICE_ITEM', 'item_id' => $service->id, 'offered_quantity' => '2', 'unit_price' => '50.0000']]]);
        $saved = QuotationDraftLine::query()->where('revision_id', $draft->revisionId)->where('source_kind', 'SERVICE_ITEM')->sole();
        $this->assertSame([$service->id, $this->hour->id, 'Inspection'], [$saved->item_id, $saved->offered_uom_id, str_contains($saved->description, 'Inspection') ? 'Inspection' : $saved->description]);
        $this->assertSame('SOURCE_INCOMPATIBLE', $this->refusal(fn () => $this->update($draft, ['add_lines' => [['source_kind' => 'SERVICE_ITEM', 'item_id' => $this->pigment->id, 'offered_quantity' => '1']]])), 'a stocked material is not a service');
        $this->assertSame('INVALID_INPUT', $this->refusal(fn () => $this->update($draft, ['add_lines' => [['source_kind' => 'SERVICE_ITEM', 'offered_quantity' => '1']]])));
        $this->assertSame('SOURCE_KIND_NOT_AUTHORISED', $this->refusal(fn () => $this->update($draft, ['add_lines' => [['source_kind' => 'ASSEMBLY_WORK', 'offered_quantity' => '1']]])));
        $this->assertSame('SOURCE_INCOMPATIBLE', $this->refusal(fn () => $this->update($draft, ['add_lines' => [['source_kind' => 'DIRECT_ITEM', 'item_id' => $service->id, 'offered_quantity' => '1']]])));
        $this->assertSame('SOURCE_NOT_FOUND', $this->refusal(fn () => $line(['item_id' => 987654])));
        $this->assertSame('SELLING_UNIT_UNSUPPORTED', $this->refusal(fn () => $line(['offered_uom_id' => $this->hour->id])), 'A package or alternate unit has no conversion evidence yet.');
        $this->assertStringContainsString('QUANTITY', $this->refusal(fn () => $line(['offered_quantity' => '1.23456'])));
        $this->assertSame(1, QuotationDraftLine::query()->count()); // only the authorised service line; every refusal saved nothing
        $this->assertNotNull(Assembly::class);
    }

    // ---- money, arithmetic, zero versus unknown --------------------------------------------------------------------------------------------------------

    public function test_the_document_net_is_the_sum_of_settled_lines_by_hand(): void
    {
        $draft = $this->create();
        // 3 × 0.3350 = 1.0050 → HALF_UP to the rupee's 2 places = 1.01 (settlement rounded up by 0.0050); twice. 2.5 × 10.1250 = 25.3125 → 25.31.
        $this->update($draft, ['add_lines' => [
            $this->manual('Prime A', '3', '0.3350'), $this->manual('Prime B', '3', '0.3350'), $this->manual('Topcoat', '2.5', '10.1250'),
        ]]);

        $preview = $this->preview($draft);

        $this->assertSame(['1.0100', '1.0100', '25.3100'], array_column(array_column($preview['lines'], 'net'), 'amount'));
        $this->assertSame('1.0050', $preview['lines'][0]['settlement']['computed']);
        $this->assertSame('25.3125', $preview['lines'][2]['settlement']['computed']);
        // The header is the sum of the settled lines (1.01 + 1.01 + 25.31), not a separately rounded raw total (1.0050 + 1.0050 + 25.3125 = 27.3225 → 27.32).
        $this->assertSame(['COMPLETE', '27.3300'], [$preview['totals']['net']['certainty'], $preview['totals']['net']['amount']]);
    }

    public function test_zero_is_a_known_price_and_no_price_is_unknown(): void
    {
        $draft = $this->create();
        $this->assertSame(['UNKNOWN', null, ['NO_LINES']], $this->netOf($this->preview($draft)), 'No lines is not a known zero.');

        $this->update($draft, ['add_lines' => [$this->manual('Free survey', '1', '0.0000')]]);
        $this->assertSame(['COMPLETE', '0.0000', []], $this->netOf($this->preview($draft)), 'An explicit zero price is a known zero.');
        $this->assertSame('0.0000', QuotationDraftLine::query()->firstOrFail()->unit_price);

        $this->update($draft, ['add_lines' => [$this->manual('Supply', '2', null)]]);
        $preview = $this->preview($draft);
        $this->assertSame('PARTIAL', $preview['totals']['net']['certainty'], 'A known zero plus an unknown is a lower bound, never a complete total.');
        $this->assertNull($preview['totals']['net']['amount']);
        $this->assertSame('0.0000', $preview['totals']['net']['known_amount']);
        $this->assertSame(['UNKNOWN', null, 'PRICE_NOT_ENTERED'], [$preview['lines'][1]['net']['certainty'], $preview['lines'][1]['net']['amount'], $preview['lines'][1]['net']['reasons'][0]]);
        $this->assertNull(QuotationDraftLine::query()->where('description', 'Supply')->value('unit_price'));

        $onlyUnknown = $this->create();
        $this->update($onlyUnknown, ['add_lines' => [$this->manual('Supply', '2', null)]]);
        $this->assertSame('UNKNOWN', $this->preview($onlyUnknown)['totals']['net']['certainty']);
    }

    /** @return array{0: string, 1: string|null, 2: list<string>} */
    private function netOf(array $preview): array
    {
        return [$preview['totals']['net']['certainty'], $preview['totals']['net']['amount'], $preview['totals']['net']['reasons']];
    }

    public function test_tax_is_unknown_and_nothing_is_issue_ready_and_no_cost_is_disclosed(): void
    {
        $draft = $this->create();
        $this->update($draft, ['add_lines' => [
            $this->manual('Coat', '1', '10.0000'),
            ['source_kind' => 'DIRECT_ITEM', 'item_id' => $this->pigment->id, 'offered_quantity' => '2', 'unit_price' => '5.0000'],
        ]]);

        $preview = $this->preview($draft);

        $this->assertSame('UNKNOWN', $preview['tax']['certainty']);
        $this->assertSame(['UNKNOWN', null], [$preview['tax']['total_including_tax']['certainty'], $preview['tax']['total_including_tax']['amount']]);
        $this->assertFalse($preview['issue_ready']);
        $this->assertSame(['evaluated' => 0, 'status' => 'NOT_CONFIGURED', 'blocks_issue' => null], $preview['controls']);
        $this->assertSame('PREVIEW_ONLY', $preview['authority']);
        $this->assertStringNotContainsString('cost', strtolower(json_encode($preview)), 'A seller holds no cost duty, so no cost or margin appears.');
        $this->assertSame('20.0000', $preview['totals']['net']['amount']);
    }

    public function test_preview_writes_nothing(): void
    {
        $draft = $this->create();
        $this->update($draft, ['add_lines' => [$this->manual('Coat', '1', '10.0000')]]);
        $generation = QuotationRevision::findOrFail($draft->revisionId)->input_generation;
        $receipts = QuotationOperationReceipt::query()->count();

        $this->preview($draft);
        $this->preview($draft);

        $this->assertSame($generation, QuotationRevision::findOrFail($draft->revisionId)->input_generation);
        $this->assertSame($receipts, QuotationOperationReceipt::query()->count());
    }

    public function test_preview_needs_a_recipient_and_a_pricing_date_to_settle(): void
    {
        $draft = $this->create(['recipient_party_id' => null, 'pricing_date' => null]);
        $this->assertSame('RECIPIENT_REQUIRED', $this->refusal(fn () => $this->preview($draft)));

        $this->update($draft, ['recipient_party_id' => $this->prospect->id, 'add_lines' => [$this->manual('Coat', '1', null)]]);
        $this->assertSame('PRICING_DATE_REQUIRED', $this->refusal(fn () => $this->update($draft, ['add_lines' => [$this->manual('Priced', '1', '5')]])), 'A price cannot be entered without a pricing date.');
        $this->assertSame('UNKNOWN', $this->preview($draft)['totals']['net']['certainty']);
    }

    public function test_a_unit_price_finer_than_the_policy_allows_is_refused_not_rounded(): void
    {
        $draft = $this->create();

        $this->assertSame('UNIT_PRICE_PRECISION_EXCEEDED', $this->refusal(fn () => $this->update($draft, ['add_lines' => [$this->manual('x', '1', '0.33501')]])));
        $this->assertSame('INVALID_AMOUNT', $this->refusal(fn () => $this->update($draft, ['add_lines' => [$this->manual('x', '1', '1e2')]])));
        $this->assertSame('NEGATIVE_AMOUNT', $this->refusal(fn () => $this->update($draft, ['add_lines' => [$this->manual('x', '1', '-1')]])));
        $this->assertSame(0, QuotationDraftLine::query()->count());
    }

    // ---- dual money and FX -----------------------------------------------------------------------------------------------------------------------------

    public function test_an_entered_price_stores_its_currency_pinned_fx_and_base_amount(): void
    {
        $this->rate($this->usd, '280');
        $draft = $this->create(['currency_id' => $this->usd->id]);
        $this->update($draft, ['add_lines' => [$this->manual('Coat', '4', '0.3350')]]);

        $line = QuotationDraftLine::query()->firstOrFail();
        // 0.3350 USD × 280 = 93.8000 PKR, hand-derived; the evidence names the rate, its date and the identity leg of the base currency.
        $this->assertSame(['0.3350', $this->usd->id, $this->pkr->id, '93.8000'], [$line->unit_price, $line->price_currency_id, $line->base_currency_id, $line->base_unit_price]);
        $this->assertSame('280.000000000', $line->fx_evidence['source_to_base_leg']['rate']);
        $this->assertTrue($line->fx_evidence['quote_to_base_leg']['identity']);
        $this->assertSame($this->today, $line->fx_evidence['requested_date']);
        $this->assertSame(64, strlen($line->fx_fingerprint));
        // 4 × 0.3350 = 1.3400 USD → 1.34; that is the quotation-currency net, in the quotation's own currency.
        $this->assertSame('1.3400', $this->preview($draft)['lines'][0]['net']['amount']);
    }

    public function test_the_company_currency_is_an_identity_with_no_rate(): void
    {
        $draft = $this->create();
        $this->update($draft, ['add_lines' => [$this->manual('Coat', '1', '7.1234')]]);

        $line = QuotationDraftLine::query()->firstOrFail();
        $this->assertSame('IDENTITY', $line->fx_evidence['route']);
        $this->assertSame(['7.1234', '7.1234'], [$line->unit_price, $line->base_unit_price]);
    }

    public function test_a_missing_rate_refuses_the_money_write_but_not_a_non_money_edit(): void
    {
        $draft = $this->create(['currency_id' => $this->usd->id]);

        $this->assertSame('FX_RATE_MISSING', $this->refusal(fn () => $this->update($draft, ['add_lines' => [$this->manual('Priced', '1', '5')]])));
        $this->assertSame(0, QuotationDraftLine::query()->count(), 'No incomplete monetary row was written.');
        $this->update($draft, ['add_lines' => [$this->manual('Unpriced', '1', null)], 'valid_through_local_date' => '2027-02-01']);
        $this->assertSame(1, QuotationDraftLine::query()->count());
        $this->assertNull(QuotationDraftLine::query()->value('unit_price'));
    }

    public function test_the_database_refuses_a_half_written_money_row(): void
    {
        $draft = $this->create();
        $this->update($draft, ['add_lines' => [$this->manual('Coat', '1', null)]]);
        $line = QuotationDraftLine::query()->firstOrFail();

        $this->assertDbRefuses(fn () => DB::table('quotation_draft_lines')->where('id', $line->id)->update(['unit_price' => '5.0000']));
        $this->assertDbRefuses(fn () => DB::table('quotation_draft_lines')->where('id', $line->id)->update(['priced_quantity' => '1.0000']));
    }

    public function test_changing_the_pricing_date_converts_priced_lines_again_or_refuses(): void
    {
        $yesterday = now(Company::findOrFail(1)->timezone)->subDay()->format('Y-m-d');
        $this->rate($this->usd, '280');
        $this->rate($this->usd, '300', $yesterday);
        $draft = $this->create(['currency_id' => $this->usd->id]);
        $this->update($draft, ['add_lines' => [$this->manual('Coat', '1', '1.0000')]]);
        $this->assertSame('280.0000', QuotationDraftLine::query()->value('base_unit_price'));

        $this->update($draft, ['pricing_date' => $yesterday]);
        $line = QuotationDraftLine::query()->firstOrFail();
        $this->assertSame(['300.0000', $yesterday], [$line->base_unit_price, $line->fx_evidence['requested_date']]);

        $this->assertSame('FX_RATE_MISSING', $this->refusal(fn () => $this->update($draft, ['pricing_date' => now(Company::findOrFail(1)->timezone)->subDays(5)->format('Y-m-d')])));
        $this->assertSame($yesterday, QuotationRevision::findOrFail($draft->revisionId)->pricing_date->format('Y-m-d'), 'The refused change stored nothing.');
        $this->assertSame('PRICING_DATE_REQUIRED', $this->refusal(fn () => $this->update($draft, ['pricing_date' => null])));
    }

    public function test_the_currency_cannot_change_under_priced_lines_and_the_database_agrees(): void
    {
        $this->rate($this->usd, '280');
        $draft = $this->create();
        $this->update($draft, ['add_lines' => [$this->manual('Coat', '1', '1')]]);

        $this->assertSame('CURRENCY_LOCKED_BY_PRICES', $this->refusal(fn () => $this->update($draft, ['currency_id' => $this->usd->id])));
        $this->assertDbRefuses(fn () => DB::table('quotation_revisions')->where('id', $draft->revisionId)->update(['currency_id' => $this->usd->id]));
        $line = QuotationDraftLine::query()->firstOrFail();
        $this->update($draft, ['update_lines' => [['id' => $line->id, 'unit_price' => null]]]);
        $this->update($draft, ['currency_id' => $this->usd->id]);
        $this->assertSame($this->usd->id, QuotationRevision::findOrFail($draft->revisionId)->currency_id);
    }

    public function test_a_later_rate_edit_changes_nothing_stored_and_preview_says_so(): void
    {
        $rate = $this->rate($this->usd, '280');
        $draft = $this->create(['currency_id' => $this->usd->id]);
        $this->update($draft, ['add_lines' => [$this->manual('Coat', '1', '1.0000')]]);
        $this->assertSame('SAME', $this->preview($draft)['lines'][0]['fx']['current']);

        $rate->update(['rate' => '290']);

        $this->assertSame('280.0000', QuotationDraftLine::query()->value('base_unit_price'));
        $preview = $this->preview($draft);
        $this->assertSame('CHANGED', $preview['lines'][0]['fx']['current']);
        $this->assertSame('280.0000', $preview['lines'][0]['base_unit_price']);
    }

    public function test_an_entered_price_locks_the_base_currency_and_an_unpriced_draft_does_not(): void
    {
        $draft = $this->create();
        $this->update($draft, ['add_lines' => [$this->manual('Unpriced', '1', null)]]);
        $company = Company::findOrFail(1);
        $company->update(['base_currency_id' => $this->usd->id]);
        $company->update(['base_currency_id' => $this->pkr->id]);

        $this->update($draft, ['add_lines' => [$this->manual('Priced', '1', '1')]]);

        $this->assertThrows(fn () => Company::findOrFail(1)->update(['base_currency_id' => $this->usd->id]), DomainInvariantException::class);
    }

    public function test_the_rounding_policy_prices_were_prepared_under_is_remembered(): void
    {
        $draft = $this->create();
        $this->assertNull(QuotationRevision::findOrFail($draft->revisionId)->rounding_policy_fingerprint);
        $this->update($draft, ['add_lines' => [$this->manual('Coat', '1', '1')]]);

        $revision = QuotationRevision::findOrFail($draft->revisionId);
        $this->assertSame(64, strlen($revision->rounding_policy_fingerprint));
        $this->assertSame($this->today, $revision->rounding_policy_as_of->format('Y-m-d'));
        $this->assertNull($revision->rounding_policy_version_no, 'The built-in default policy has no version.');
        $this->assertSame(['requires_review' => false, 'reasons' => [], 'non_conforming_lines' => []], $this->preview($draft)['revalidation']);

        $this->update($draft, ['update_lines' => [['id' => QuotationDraftLine::query()->value('id'), 'unit_price' => null]]]);
        $this->assertNull(QuotationRevision::findOrFail($draft->revisionId)->rounding_policy_fingerprint, 'With no price left there is nothing to remember.');
    }

    public function test_a_policy_change_after_pricing_marks_the_draft_for_review_without_rewriting_it(): void
    {
        Permission::findOrCreate(PermissionVocabulary::FINANCIAL_CONFIGURE, 'web');
        $this->preparer->givePermissionTo(PermissionVocabulary::FINANCIAL_CONFIGURE);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->preparer = $this->preparer->fresh();
        $draft = $this->create();
        $this->update($draft, ['add_lines' => [$this->manual('Coat', '3', '0.3350')]]);
        // Half-even settles 1.0050 to 1.00 where half-up gives 1.01: the same entered price now settles differently.
        $this->assertSame('1.0100', $this->preview($draft)['lines'][0]['net']['amount']);

        $policy = app(CreateRoundingPolicyDraft::class)($this->preparer, 4, SettlementRounding::HalfEven, 'Bank rounding');
        app(ActivateRoundingPolicy::class)($policy, $this->preparer, $this->today);

        $preview = $this->preview($draft);
        $this->assertSame(['requires_review' => true, 'reasons' => ['ROUNDING_POLICY_CHANGED'], 'non_conforming_lines' => []], $preview['revalidation']);
        $this->assertSame('1.0000', $preview['lines'][0]['net']['amount'], 'The preview uses the policy now in force; the stored price is untouched.');
        $this->assertSame('0.3350', QuotationDraftLine::query()->value('unit_price'));
    }

    // ---- membership ------------------------------------------------------------------------------------------------------------------------------------

    public function test_a_choice_baseline_is_counted_once_and_an_optional_line_sits_outside_the_base(): void
    {
        $draft = $this->create();
        $this->update($draft, ['add_lines' => [
            $this->manual('Base work', '1', '100.0000'),
            $this->manual('Option A', '1', '40.0000', ['membership' => 'CHOOSE_ONE', 'choice_group_ref' => 'finish', 'is_baseline' => true]),
            $this->manual('Option B', '1', '55.0000', ['membership' => 'CHOOSE_ONE', 'choice_group_ref' => 'finish']),
            $this->manual('Extra', '1', '7.0000', ['membership' => 'OPTIONAL']),
        ]]);

        $preview = $this->preview($draft);

        $this->assertSame('140.0000', $preview['totals']['net']['amount'], 'Base 100 + the baseline 40; the alternative 55 and the optional 7 are not added.');
        $this->assertSame('7.0000', $preview['totals']['optional_net']['amount']);
    }

    public function test_a_choice_group_without_a_baseline_keeps_the_total_incomplete_and_two_baselines_are_refused(): void
    {
        $draft = $this->create();
        $this->update($draft, ['add_lines' => [
            $this->manual('Base work', '1', '100.0000'),
            $this->manual('Option A', '1', '40.0000', ['membership' => 'CHOOSE_ONE', 'choice_group_ref' => 'finish']),
        ]]);
        $preview = $this->preview($draft);
        $this->assertSame(['PARTIAL', null, '100.0000'], [$preview['totals']['net']['certainty'], $preview['totals']['net']['amount'], $preview['totals']['net']['known_amount']]);
        $this->assertContains('CHOICE_BASELINE_MISSING:'.QuotationDraftLine::query()->where('description', 'Option A')->value('choice_group_id'), $preview['issues']);

        $optionA = QuotationDraftLine::query()->where('description', 'Option A')->value('id');
        $this->update($draft, ['update_lines' => [['id' => $optionA, 'is_baseline' => true]]]);
        $this->assertSame('CHOICE_BASELINE_CONFLICT', $this->refusal(fn () => $this->update($draft, ['add_lines' => [
            $this->manual('Option B', '1', '5', ['membership' => 'CHOOSE_ONE', 'choice_group_ref' => 'finish', 'is_baseline' => true]),
        ]])));
        $this->assertSame('INVALID_INPUT', $this->refusal(fn () => $this->update($draft, ['add_lines' => [$this->manual('Odd', '1', '5', ['is_baseline' => true])]])));
        $this->assertSame('INVALID_INPUT', $this->refusal(fn () => $this->update($draft, ['add_lines' => [$this->manual('Odd', '1', '5', ['membership' => 'CHOOSE_ONE'])]])));
    }

    public function test_a_baseline_moves_in_one_update_and_removing_a_line_keeps_its_scope_as_history(): void
    {
        $draft = $this->create();
        $this->update($draft, ['add_lines' => [
            $this->manual('Option A', '1', '40', ['membership' => 'CHOOSE_ONE', 'choice_group_ref' => 'finish', 'is_baseline' => true, 'scope_ref' => 'a']),
            $this->manual('Option B', '1', '55', ['membership' => 'CHOOSE_ONE', 'choice_group_ref' => 'finish', 'scope_ref' => 'b']),
        ]]);
        [$a, $b] = [QuotationDraftLine::query()->where('description', 'Option A')->value('id'), QuotationDraftLine::query()->where('description', 'Option B')->value('id')];

        $this->update($draft, ['update_lines' => [['id' => $b, 'is_baseline' => true], ['id' => $a, 'is_baseline' => false]]]);
        $this->assertSame([false, true], [QuotationDraftLine::find($a)->is_baseline, QuotationDraftLine::find($b)->is_baseline]);

        $this->update($draft, ['remove_lines' => [$a]]);
        $this->assertNull(QuotationDraftLine::find($a));
        $this->assertSame('SCOPE_ALREADY_ON_REVISION', $this->refusal(fn () => $this->update($draft, ['add_lines' => [$this->manual('B again', '1', '1', ['scope_ref' => 'b'])]])));
        $this->update($draft, ['add_lines' => [$this->manual('A again', '1', '1', ['scope_ref' => 'a'])]]);
        $this->assertSame(1, DB::table('quotation_logical_scopes')->where('intent_ref', 'a')->count(), 'The deliverable kept its identity when its line was removed and re-added.');
        $this->assertSame('LINE_NOT_FOUND', $this->refusal(fn () => $this->update($draft, ['remove_lines' => [$a]])));
    }

    private function assertDbRefuses(\Closure $call): void
    {
        try {
            DB::transaction($call);
            $this->fail('Expected the database to refuse.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
