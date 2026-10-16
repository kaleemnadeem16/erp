<?php

namespace Tests\Feature\Modules\Sales;

use App\Exceptions\DomainInvariantException;
use App\Models\Currency;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Estimating\Models\Assembly;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\ItemType;
use App\Modules\Numbering\Support\NumberingRegistry;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Models\QuotationChoiceGroup;
use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Models\QuotationFamily;
use App\Modules\Sales\Models\QuotationFamilyAssociationEvent;
use App\Modules\Sales\Models\QuotationLogicalScope;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Models\SalesStage;
use App\Modules\Sales\Numbering\QuotationNumbering;
use App\Modules\Sales\Support\Pricing\PricingSourceKind;
use App\Modules\Sales\Support\Quotation\LineMembership;
use App\Support\UomDimension;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * QL0 — the quotation aggregate's identities and editable draft, over the real database: tenancy by composite keys, family-local revisions, the one optional
 * CRM association with its chained history, logical scopes and choice groups, typed source integrity, draft-only line writes and immutable cancelled drafts
 * (R5 QL0, ADRs 0098, 0099, 0109, 0122–0124). Nothing here prices, numbers, issues or posts.
 */
class QuotationFoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Currency $pkr;

    private Uom $kg;

    private Uom $hour;

    private Item $pigment;

    private Item $inspection;

    private Party $prospect;

    private int $stageId;

    private int $otherCompany;

    private int $otherUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->pkr = Currency::firstOrCreate(['code' => 'PKR'], ['name' => 'Rupee', 'symbol' => 'Rs', 'decimal_places' => 2]);
        $this->kg = Uom::create(['code' => 'kg', 'name' => 'Kilogram', 'dimension' => UomDimension::Mass, 'factor_to_base' => '1', 'is_base' => true]);
        $this->hour = Uom::create(['code' => 'hr', 'name' => 'Hour', 'dimension' => UomDimension::Time, 'factor_to_base' => '1', 'is_base' => true]);
        $category = Category::create(['code' => 'RAW', 'name' => 'Raw', 'default_valuation_method' => 'AVCO']);
        $this->pigment = Item::create(['code' => 'PIGMENT', 'name' => 'Pigment', 'type' => ItemType::Raw, 'stock_uom_id' => $this->kg->id, 'category_id' => $category->id, 'is_sellable' => true, 'is_stocked' => true]);
        $this->inspection = Item::create(['code' => 'INSPECT', 'name' => 'Inspection', 'type' => ItemType::Service, 'stock_uom_id' => $this->hour->id, 'category_id' => $category->id, 'is_sellable' => true]);
        $this->prospect = Party::create(['code' => 'PROSPECT', 'name' => 'Prospect Ltd']);
        $this->stageId = SalesStage::create(['code' => 'Q', 'name' => 'Qualification', 'sort_order' => 10, 'status' => 'ACTIVE'])->id;
        $this->otherCompany = DB::table('companies')->insertGetId(['name' => 'Other Co', 'created_at' => now(), 'updated_at' => now()]);
        $this->otherUser = DB::table('users')->insertGetId(['company_id' => $this->otherCompany, 'name' => 'O', 'email' => 'o@other.test', 'password' => 'x', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function family(): QuotationFamily
    {
        return QuotationFamily::create(['created_by' => $this->user->id])->refresh();
    }

    /** @param array<string, mixed> $over */
    private function revision(QuotationFamily $family, array $over = []): QuotationRevision
    {
        return QuotationRevision::create(['family_id' => $family->id, 'currency_id' => $this->pkr->id, 'created_by' => $this->user->id, ...$over])->refresh();
    }

    private function scope(QuotationFamily $family, string $ref, ?QuotationLogicalScope $anchor = null): QuotationLogicalScope
    {
        return QuotationLogicalScope::create(['family_id' => $family->id, 'intent_ref' => $ref, 'overlap_anchor_id' => $anchor?->id, 'created_by' => $this->user->id])->refresh();
    }

    private function group(QuotationFamily $family, string $ref): QuotationChoiceGroup
    {
        return QuotationChoiceGroup::create(['family_id' => $family->id, 'intent_ref' => $ref, 'created_by' => $this->user->id])->refresh();
    }

    /** @param array<string, mixed> $over */
    private function line(QuotationRevision $revision, QuotationLogicalScope $scope, array $over = []): QuotationDraftLine
    {
        return QuotationDraftLine::create(['family_id' => $revision->family_id, 'revision_id' => $revision->id, 'logical_scope_id' => $scope->id, 'description' => 'Pigment, 25 kg bags', 'source_kind' => 'DIRECT_ITEM',
            'item_id' => $this->pigment->id, 'offered_quantity' => '2', 'offered_uom_id' => $this->kg->id, 'created_by' => $this->user->id, ...$over])->refresh();
    }

    /** @return int the id of a company-1 opportunity */
    private function opportunity(string $title = 'Warehouse floor'): int
    {
        return DB::table('sales_opportunities')->insertGetId(['company_id' => 1, 'title' => $title, 'party_id' => $this->prospect->id, 'owner_id' => $this->user->id, 'stage_id' => $this->stageId,
            'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Records an association change the way a correct writer does: the event, then the family, together. */
    private function associate(QuotationFamily $family, ?int $opportunityId, ?int $generation = null): void
    {
        DB::transaction(function () use ($family, $opportunityId, $generation): void {
            $fresh = QuotationFamily::findOrFail($family->id);
            $generation ??= $fresh->association_generation + 1;
            QuotationFamilyAssociationEvent::create(['family_id' => $family->id, 'generation' => $generation, 'old_opportunity_id' => $fresh->current_opportunity_id,
                'new_opportunity_id' => $opportunityId, 'actor_id' => $this->user->id, 'occurred_at' => now()]);
            $fresh->forceFill(['current_opportunity_id' => $opportunityId, 'association_generation' => $generation])->save();
        });
    }

    /** The database refuses the statement. A savepoint keeps the surrounding test transaction usable after Postgres aborts the failed one. */
    private function assertDbRefuses(Closure $call, ?string $needle = null): void
    {
        try {
            DB::transaction($call);
            $this->fail('Expected the database to refuse.');
        } catch (QueryException $refusal) {
            if ($needle !== null) {
                $this->assertStringContainsString($needle, $refusal->getMessage());
            }
            $this->addToAssertionCount(1);
        }
    }

    public function test_a_family_has_explicit_revisions_whose_ordinals_the_database_allocates(): void
    {
        $family = $this->family();
        $first = $this->revision($family);
        $second = $this->revision($family, ['predecessor_revision_id' => $first->id]);
        $third = $this->revision($family, ['predecessor_revision_id' => $second->id]);

        $this->assertSame([1, 2, 3], [$first->revision_ordinal, $second->revision_ordinal, $third->revision_ordinal]);
        $this->assertSame(3, $family->fresh()->revision_generation);
        $this->assertSame([$first->id, $second->id], [$second->predecessor->id, $third->predecessor->id]);
        $this->assertSame([1, 2, 3], $family->revisions->pluck('revision_ordinal')->all());
        $this->assertSame(['DRAFT', 1], [$first->state, $first->input_generation]);
        // A second family counts for itself.
        $other = $this->revision($this->family());
        $this->assertSame(1, $other->revision_ordinal);
        // A caller cannot choose another ordinal, and cannot create a revision that is not a draft.
        $this->assertDbRefuses(fn () => DB::table('quotation_revisions')->insert(['company_id' => 1, 'family_id' => $family->id, 'revision_ordinal' => 9, 'currency_id' => $this->pkr->id,
            'created_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now()]), 'allocated by the family');
        $this->assertDbRefuses(fn () => DB::table('quotation_revisions')->insert(['company_id' => 1, 'family_id' => $family->id, 'state' => 'CANCELLED_DRAFT', 'currency_id' => $this->pkr->id,
            'created_by' => $this->user->id, 'cancelled_at' => now(), 'cancelled_by' => $this->user->id, 'cancellation_reason' => 'x', 'created_at' => now(), 'updated_at' => now()]));
        $this->assertSame(3, $family->fresh()->revision_generation, 'A refused insert allocates nothing.');
    }

    public function test_a_predecessor_must_belong_to_the_same_family(): void
    {
        $a = $this->revision($this->family());
        $b = $this->family();

        $this->assertDbRefuses(fn () => $this->revision($b, ['predecessor_revision_id' => $a->id]));
        $this->assertSame(0, QuotationRevision::query()->where('family_id', $b->id)->count());
    }

    public function test_a_party_backed_prospect_without_a_customer_account_is_a_recipient_and_an_incomplete_draft_has_none(): void
    {
        $this->assertNull($this->prospect->customerAccount);
        $family = $this->family();

        $withRecipient = $this->revision($family, ['recipient_party_id' => $this->prospect->id]);
        $incomplete = $this->revision($family);

        $this->assertSame($this->prospect->id, $withRecipient->recipient->id);
        $this->assertNull($incomplete->recipient_party_id);
        $this->assertSame($this->pkr->id, $incomplete->currency->id, 'One currency, explicit even in a draft.');
        $this->assertNull($incomplete->pricing_date);
    }

    public function test_the_crm_association_is_optional_and_every_change_is_a_chained_event(): void
    {
        $family = $this->family();
        $this->assertNull($family->current_opportunity_id);
        $this->assertSame(0, $family->association_generation);

        $one = $this->opportunity('First');
        $two = $this->opportunity('Second');
        $this->associate($family, $one);
        $this->associate($family, $two);
        $this->associate($family, null);

        $family->refresh();
        $this->assertSame([null, 3], [$family->current_opportunity_id, $family->association_generation]);
        $events = $family->associationEvents;
        $this->assertSame([[null, $one], [$one, $two], [$two, null]], $events->map(fn ($e) => [$e->old_opportunity_id, $e->new_opportunity_id])->all());
        $this->assertSame([1, 2, 3], $events->pluck('generation')->all());
        $this->assertSame(3, $family->associationEvents->count());
        $this->assertSame(0, $this->family()->associationEvents->count(), 'A family created without an opportunity needs no event.');
    }

    public function test_the_association_cannot_change_without_its_event_or_break_the_chain(): void
    {
        $family = $this->family();
        $one = $this->opportunity('First');
        $two = $this->opportunity('Second');
        $this->associate($family, $one);
        DB::statement('SET CONSTRAINTS quotation_family_association_check, quotation_association_event_check IMMEDIATE');

        // The family moved with no event behind it, or an event with no family change, or an event that skips a generation or misstates the old value.
        $this->assertDbRefuses(fn () => DB::table('quotation_families')->where('id', $family->id)->update(['current_opportunity_id' => $two, 'association_generation' => 2]), 'latest recorded association event');
        $this->assertDbRefuses(fn () => DB::table('quotation_families')->where('id', $family->id)->update(['association_generation' => 5]), 'latest recorded association event');
        $this->assertDbRefuses(fn () => DB::table('quotation_family_association_events')->insert(['company_id' => 1, 'family_id' => $family->id, 'generation' => 3, 'old_opportunity_id' => $one,
            'new_opportunity_id' => $two, 'actor_id' => $this->user->id, 'occurred_at' => now()]), 'continue the chain');
        $this->assertDbRefuses(fn () => DB::table('quotation_family_association_events')->insert(['company_id' => 1, 'family_id' => $family->id, 'generation' => 2, 'old_opportunity_id' => $two,
            'new_opportunity_id' => null, 'actor_id' => $this->user->id, 'occurred_at' => now()]), 'continue the chain');
        $this->assertDbRefuses(fn () => DB::table('quotation_family_association_events')->insert(['company_id' => 1, 'family_id' => $family->id, 'generation' => 2, 'old_opportunity_id' => $one,
            'new_opportunity_id' => $one, 'actor_id' => $this->user->id, 'occurred_at' => now()]));
        $other = $this->family();
        $this->assertDbRefuses(fn () => DB::table('quotation_family_association_events')->insert(['company_id' => 1, 'family_id' => $other->id, 'generation' => 1, 'old_opportunity_id' => $one,
            'new_opportunity_id' => $two, 'actor_id' => $this->user->id, 'occurred_at' => now()]), 'starts from no opportunity');
        DB::statement('SET CONSTRAINTS quotation_family_association_check, quotation_association_event_check DEFERRED');

        $family->refresh();
        $this->assertSame([$one, 1], [$family->current_opportunity_id, $family->association_generation]);
    }

    public function test_association_history_is_append_only_and_families_cannot_be_rewound_or_deleted(): void
    {
        $family = $this->family();
        $this->associate($family, $this->opportunity());

        $this->assertDbRefuses(fn () => DB::table('quotation_family_association_events')->where('family_id', $family->id)->update(['reason' => 'edited']), 'append-only');
        $this->assertDbRefuses(fn () => DB::table('quotation_family_association_events')->where('family_id', $family->id)->delete(), 'append-only');
        $this->assertDbRefuses(fn () => DB::table('quotation_families')->where('id', $family->id)->delete(), 'history');
        $this->assertDbRefuses(fn () => DB::table('quotation_families')->where('id', $family->id)->update(['association_generation' => 0]));
        $this->assertDbRefuses(fn () => DB::table('quotation_families')->where('id', $family->id)->update(['created_by' => $this->user->id + 1]));
    }

    public function test_every_cross_company_link_is_refused_by_the_composite_keys(): void
    {
        $theirs = DB::table('quotation_families')->insertGetId(['company_id' => $this->otherCompany, 'created_by' => $this->otherUser, 'created_at' => now(), 'updated_at' => now()]);
        $opportunity = $this->opportunity();
        $mine = $this->family();

        // Their family cannot point at my opportunity, party, item, unit or user; my family cannot be the parent of their revision. Each is refused by its own key.
        $this->assertDbRefuses(fn () => DB::table('quotation_families')->where('id', $theirs)->update(['current_opportunity_id' => $opportunity, 'association_generation' => 1]), 'quotation_families_opportunity_fk');
        $this->assertDbRefuses(fn () => DB::table('quotation_family_association_events')->insert(['company_id' => $this->otherCompany, 'family_id' => $theirs, 'generation' => 1, 'old_opportunity_id' => null,
            'new_opportunity_id' => $opportunity, 'actor_id' => $this->otherUser, 'occurred_at' => now()]), 'quotation_association_new_fk');
        $this->assertDbRefuses(fn () => DB::table('quotation_revisions')->insert(['company_id' => $this->otherCompany, 'family_id' => $mine->id, 'currency_id' => $this->pkr->id, 'created_by' => $this->otherUser,
            'created_at' => now(), 'updated_at' => now()]), 'quotation_revisions_family_fk');
        $this->assertDbRefuses(fn () => DB::table('quotation_revisions')->insert(['company_id' => $this->otherCompany, 'family_id' => $theirs, 'recipient_party_id' => $this->prospect->id, 'currency_id' => $this->pkr->id,
            'created_by' => $this->otherUser, 'created_at' => now(), 'updated_at' => now()]), 'quotation_revisions_party_fk');
        $this->assertDbRefuses(fn () => DB::table('quotation_revisions')->insert(['company_id' => $this->otherCompany, 'family_id' => $theirs, 'currency_id' => $this->pkr->id, 'created_by' => $this->user->id,
            'created_at' => now(), 'updated_at' => now()]), 'quotation_revisions_created_by_fk');
        $this->assertDbRefuses(fn () => DB::table('quotation_logical_scopes')->insert(['company_id' => $this->otherCompany, 'family_id' => $mine->id, 'intent_ref' => 'x', 'created_by' => $this->otherUser, 'created_at' => now()]), 'quotation_scopes_family_fk');

        $revision = DB::table('quotation_revisions')->insertGetId(['company_id' => $this->otherCompany, 'family_id' => $theirs, 'currency_id' => $this->pkr->id, 'created_by' => $this->otherUser,
            'created_at' => now(), 'updated_at' => now()]);
        $scope = DB::table('quotation_logical_scopes')->insertGetId(['company_id' => $this->otherCompany, 'family_id' => $theirs, 'intent_ref' => 'x', 'created_by' => $this->otherUser, 'created_at' => now()]);
        $theirUom = DB::table('uoms')->insertGetId(['company_id' => $this->otherCompany, 'code' => 'kg', 'name' => 'Kilogram', 'dimension' => 'MASS', 'factor_to_base' => 1, 'is_base' => true, 'created_at' => now(), 'updated_at' => now()]);
        $theirAssembly = DB::table('assemblies')->insertGetId(['company_id' => $this->otherCompany, 'code' => 'T', 'name' => 'T', 'work_uom_id' => $theirUom, 'margin_type' => 'MARKUP', 'margin_pct' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $theirVersion = DB::table('assembly_versions')->insertGetId(['assembly_id' => $theirAssembly, 'version_no' => 1, 'status' => 'DRAFT', 'created_at' => now(), 'updated_at' => now()]);
        $line = fn (array $over) => fn () => DB::table('quotation_draft_lines')->insert(['company_id' => $this->otherCompany, 'family_id' => $theirs, 'revision_id' => $revision, 'logical_scope_id' => $scope, 'description' => 'x',
            'offered_quantity' => 1, 'created_by' => $this->otherUser, 'created_at' => now(), 'updated_at' => now(), ...$over]);
        $this->assertDbRefuses($line(['source_kind' => 'DIRECT_ITEM', 'item_id' => $this->pigment->id, 'offered_uom_id' => $theirUom]), 'quotation_draft_lines_item_fk');
        $this->assertDbRefuses($line(['source_kind' => 'ASSEMBLY_WORK', 'assembly_version_id' => $theirVersion, 'offered_uom_id' => $this->kg->id]), 'quotation_draft_lines_uom_fk');
        // The scoped model never even sees their rows.
        $this->assertSame(0, QuotationFamily::query()->whereKey($theirs)->count());
        $this->assertSame(0, QuotationRevision::query()->whereKey($revision)->count());
    }

    public function test_logical_scopes_are_family_identities_not_source_ids_and_alternatives_share_an_anchor(): void
    {
        $family = $this->family();
        $revision = $this->revision($family);
        $floor = $this->scope($family, 'floor-coating');
        $premium = $this->scope($family, 'floor-coating-premium', $floor);
        $again = $this->scope($family, 'floor-coating-second-room');

        // The same Item twice is two scopes: nothing merges them.
        $a = $this->line($revision, $floor);
        $b = $this->line($revision, $again);
        $this->assertSame($a->item_id, $b->item_id);
        $this->assertNotSame($a->logical_scope_id, $b->logical_scope_id);

        $this->assertSame($floor->id, $floor->effectiveAnchorId(), 'A scope with no anchor is its own.');
        $this->assertSame($floor->id, $premium->effectiveAnchorId());
        // One scope appears once in a revision; an intent is unique per family but free in another.
        $this->assertDbRefuses(fn () => $this->line($revision, $floor));
        $this->assertDbRefuses(fn () => $this->scope($family, 'floor-coating'));
        $this->scope($this->family(), 'floor-coating');
        // An anchor must be in the same family, and identities are append-only.
        $foreign = $this->scope($this->family(), 'elsewhere');
        $this->assertDbRefuses(fn () => $this->scope($family, 'x', $foreign));
        $this->assertDbRefuses(fn () => DB::table('quotation_logical_scopes')->where('id', $floor->id)->update(['intent_ref' => 'renamed']), 'append-only');
        $this->assertDbRefuses(fn () => DB::table('quotation_logical_scopes')->where('id', $floor->id)->delete(), 'history');
        $this->assertDbRefuses(fn () => $this->scope($family, '  '));
    }

    public function test_choose_one_lines_share_a_group_with_a_single_baseline_and_other_lines_have_neither(): void
    {
        $family = $this->family();
        $revision = $this->revision($family);
        $group = $this->group($family, 'finish');
        $matt = $this->scope($family, 'finish-matt');
        $gloss = $this->scope($family, 'finish-gloss', $matt);
        $base = $this->scope($family, 'base');

        $m = $this->line($revision, $matt, ['membership' => 'CHOOSE_ONE', 'choice_group_id' => $group->id, 'is_baseline' => true]);
        $g = $this->line($revision, $gloss, ['membership' => 'CHOOSE_ONE', 'choice_group_id' => $group->id]);
        $this->line($revision, $base, ['membership' => 'OPTIONAL']);

        $this->assertSame([LineMembership::ChooseOne, true, false], [$m->membership(), $m->is_baseline, $g->is_baseline]);
        // A second baseline in the same group and revision, a group on a base line, a baseline on a base line, and a group of another family are refused.
        $this->assertDbRefuses(fn () => $g->update(['is_baseline' => true]));
        $this->assertDbRefuses(fn () => $this->line($revision, $this->scope($family, 'x1'), ['membership' => 'BASE', 'choice_group_id' => $group->id]));
        $this->assertDbRefuses(fn () => $this->line($revision, $this->scope($family, 'x2'), ['membership' => 'BASE', 'is_baseline' => true]));
        $this->assertDbRefuses(fn () => $this->line($revision, $this->scope($family, 'x3'), ['membership' => 'CHOOSE_ONE']));
        $this->assertDbRefuses(fn () => $this->line($revision, $this->scope($family, 'x4'), ['membership' => 'CHOOSE_ONE', 'choice_group_id' => $this->group($this->family(), 'foreign')->id]));
        $this->assertDbRefuses(fn () => $this->line($revision, $this->scope($family, 'x5'), ['membership' => 'PACKAGE']));
        // A new revision may carry its own baseline for the same group.
        $next = $this->revision($family, ['predecessor_revision_id' => $revision->id]);
        $this->line($next, $matt, ['membership' => 'CHOOSE_ONE', 'choice_group_id' => $group->id, 'is_baseline' => true]);
        // Groups are append-only identities.
        $this->assertDbRefuses(fn () => DB::table('quotation_choice_groups')->where('id', $group->id)->delete(), 'history');
        $this->assertDbRefuses(fn () => DB::table('quotation_choice_groups')->where('id', $group->id)->update(['intent_ref' => 'x']), 'append-only');
    }

    public function test_each_source_kind_has_exactly_its_own_references_and_maps_to_the_pricing_kernel(): void
    {
        $family = $this->family();
        $revision = $this->revision($family);
        $assembly = Assembly::create(['code' => 'ASM-1', 'name' => 'Floor system', 'work_uom_id' => $this->kg->id, 'margin_type' => 'MARKUP', 'margin_pct' => '0']);
        $version = $assembly->versions()->create(['version_no' => 1])->refresh();

        $direct = $this->line($revision, $this->scope($family, 'd'));
        $service = $this->line($revision, $this->scope($family, 's'), ['source_kind' => 'SERVICE_ITEM', 'item_id' => $this->inspection->id, 'offered_uom_id' => $this->hour->id]);
        $assemblyLine = $this->line($revision, $this->scope($family, 'a'), ['source_kind' => 'ASSEMBLY_WORK', 'item_id' => null, 'assembly_version_id' => $version->id]);
        $manual = $this->line($revision, $this->scope($family, 'm'), ['source_kind' => 'MANUAL', 'item_id' => null, 'offered_uom_id' => null, 'manual_measure' => 'lump sum', 'description' => 'Site clean-up']);

        $this->assertSame([PricingSourceKind::DirectItem, PricingSourceKind::ServiceItem, PricingSourceKind::AssemblyWork, PricingSourceKind::Manual],
            [$direct->sourceKind(), $service->sourceKind(), $assemblyLine->sourceKind(), $manual->sourceKind()]);
        $this->assertSame([PricingSourceKind::DirectItem, $this->pigment->id], [$direct->pricingSource()->kind, $direct->pricingSource()->id]);
        $this->assertSame($this->inspection->id, $service->pricingSource()->id);
        $this->assertSame($version->id, $assemblyLine->pricingSource()->id);
        $this->assertSame(['Site clean-up', 'lump sum'], [$manual->pricingSource()->description, $manual->pricingSource()->measure]);

        $n = 0;
        $bad = fn (array $over) => fn () => $this->line($revision, $this->scope($family, 'bad'.(++$n)), $over);
        // Wrong or mixed references, a missing unit, a manual with a unit or no measure.
        $this->assertDbRefuses($bad(['source_kind' => 'ASSEMBLY_WORK', 'assembly_version_id' => $version->id]));
        $this->assertDbRefuses($bad(['assembly_version_id' => $version->id]));
        $this->assertDbRefuses($bad(['source_kind' => 'MANUAL', 'manual_measure' => 'lump', 'offered_uom_id' => null]));
        $this->assertDbRefuses($bad(['source_kind' => 'MANUAL', 'item_id' => null, 'manual_measure' => 'lump']));
        $this->assertDbRefuses($bad(['source_kind' => 'MANUAL', 'item_id' => null, 'offered_uom_id' => null, 'manual_measure' => '  ']));
        $this->assertDbRefuses($bad(['offered_uom_id' => null]));
        $this->assertDbRefuses($bad(['item_id' => null]));
        $this->assertDbRefuses($bad(['source_kind' => 'BOGUS']));
        // Item nature: a service cannot be a direct goods source, and goods or a resource cannot be a service source.
        $this->assertDbRefuses($bad(['item_id' => $this->inspection->id, 'offered_uom_id' => $this->hour->id]), 'direct item source');
        $this->assertDbRefuses($bad(['source_kind' => 'SERVICE_ITEM']), 'service item');
        $this->assertDbRefuses($bad(['assembly_version_id' => 999999, 'source_kind' => 'ASSEMBLY_WORK', 'item_id' => null]));
    }

    public function test_an_assembly_version_of_another_company_is_refused(): void
    {
        $family = $this->family();
        $revision = $this->revision($family);
        $theirUom = DB::table('uoms')->insertGetId(['company_id' => $this->otherCompany, 'code' => 'kg', 'name' => 'Kilogram', 'dimension' => 'MASS', 'factor_to_base' => 1, 'is_base' => true, 'created_at' => now(), 'updated_at' => now()]);
        $foreignAssembly = DB::table('assemblies')->insertGetId(['company_id' => $this->otherCompany, 'code' => 'THEIRS', 'name' => 'Theirs', 'work_uom_id' => $theirUom, 'margin_type' => 'MARKUP',
            'margin_pct' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $foreignVersion = DB::table('assembly_versions')->insertGetId(['assembly_id' => $foreignAssembly, 'version_no' => 1, 'status' => 'DRAFT', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertDbRefuses(fn () => $this->line($revision, $this->scope($family, 'a'), ['source_kind' => 'ASSEMBLY_WORK', 'item_id' => null, 'assembly_version_id' => $foreignVersion]), 'another company');
    }

    public function test_offered_quantities_are_exact_positive_and_never_rounded(): void
    {
        $family = $this->family();
        $revision = $this->revision($family);
        $this->assertSame('1.2750', $this->line($revision, $this->scope($family, 'q1'), ['offered_quantity' => '1.275'])->offered_quantity);

        foreach (['1.27501' => 'QUANTITY_NOT_REPRESENTABLE', '0' => 'QUANTITY_ZERO', '-1' => 'QUANTITY_NEGATIVE', 'abc' => 'QUANTITY_INVALID', '1e3' => 'QUANTITY_INVALID'] as $quantity => $code) {
            try {
                $this->line($revision, $this->scope($family, 'q'.md5($quantity)), ['offered_quantity' => $quantity]);
                $this->fail("{$quantity} should be refused.");
            } catch (DomainInvariantException $refusal) {
                $this->assertSame($code, $refusal->reasonCode, $quantity);
            }
        }
        try {
            $this->line($revision, $this->scope($family, 'float'), ['offered_quantity' => 1.5]);
            $this->fail('A float is not an exact quantity.');
        } catch (DomainInvariantException $refusal) {
            $this->assertSame('QUANTITY_INVALID', $refusal->reasonCode);
        }
        $this->assertDbRefuses(fn () => DB::table('quotation_draft_lines')->insert(['company_id' => 1, 'family_id' => $family->id, 'revision_id' => $revision->id, 'logical_scope_id' => $this->scope($family, 'raw')->id,
            'description' => 'x', 'source_kind' => 'DIRECT_ITEM', 'item_id' => $this->pigment->id, 'offered_quantity' => 0, 'offered_uom_id' => $this->kg->id, 'created_by' => $this->user->id,
            'created_at' => now(), 'updated_at' => now()]));
        $this->assertSame(1, QuotationDraftLine::query()->where('revision_id', $revision->id)->count());
    }

    public function test_lines_and_header_changes_advance_the_input_generation(): void
    {
        $family = $this->family();
        $revision = $this->revision($family);
        $this->assertSame(1, $revision->input_generation);

        $line = $this->line($revision, $this->scope($family, 'g1'));
        $this->assertSame(2, $revision->fresh()->input_generation);
        $line->update(['offered_quantity' => '3']);
        $this->assertSame(3, $revision->fresh()->input_generation);
        $line->delete();
        $this->assertSame(4, $revision->fresh()->input_generation);

        $revision->refresh()->update(['recipient_party_id' => $this->prospect->id]);
        $this->assertSame(5, $revision->fresh()->input_generation, 'A material header change advances it too.');
        $revision->refresh()->update(['valid_through_local_date' => '2026-12-31', 'pricing_date' => '2026-10-16']);
        $this->assertSame(6, $revision->fresh()->input_generation, 'Several fields in one write advance it once.');
        $revision->refresh()->touch();
        $this->assertSame(6, $revision->fresh()->input_generation, 'A write that changes nothing material does not.');
        $this->assertDbRefuses(fn () => DB::table('quotation_revisions')->where('id', $revision->id)->update(['input_generation' => 9]));
    }

    public function test_a_revision_keeps_its_identity_and_is_never_deleted(): void
    {
        $family = $this->family();
        $first = $this->revision($family);
        $second = $this->revision($family);

        foreach ([['revision_ordinal' => 7], ['family_id' => $this->family()->id], ['predecessor_revision_id' => $first->id], ['created_by' => $this->user->id + 1]] as $change) {
            $this->assertDbRefuses(fn () => DB::table('quotation_revisions')->where('id', $second->id)->update($change));
        }
        $this->assertDbRefuses(fn () => DB::table('quotation_revisions')->where('id', $second->id)->delete(), 'history');
        $this->assertSame(2, QuotationRevision::query()->where('family_id', $family->id)->count());
    }

    public function test_a_cancelled_draft_is_terminal_and_nothing_can_be_written_under_it(): void
    {
        $family = $this->family();
        $revision = $this->revision($family);
        $scope = $this->scope($family, 'c');
        $line = $this->line($revision, $scope);

        // Cancelling needs its facts and a reason.
        $this->assertDbRefuses(fn () => $revision->update(['state' => 'CANCELLED_DRAFT']));
        $this->assertDbRefuses(fn () => $revision->update(['state' => 'CANCELLED_DRAFT', 'cancelled_at' => now(), 'cancelled_by' => $this->user->id, 'cancellation_reason' => '  ']));
        $revision->refresh()->update(['state' => 'CANCELLED_DRAFT', 'cancelled_at' => now(), 'cancelled_by' => $this->user->id, 'cancellation_reason' => 'Customer withdrew the enquiry']);

        $this->assertFalse($revision->fresh()->isDraft());
        $this->assertDbRefuses(fn () => $revision->update(['state' => 'DRAFT', 'cancelled_at' => null, 'cancelled_by' => null, 'cancellation_reason' => null]), 'cancelled quotation draft is immutable');
        $this->assertDbRefuses(fn () => $revision->update(['valid_through_local_date' => '2027-01-01']), 'immutable');
        $this->assertDbRefuses(fn () => $this->line($revision, $this->scope($family, 'late')), 'draft revision');
        $this->assertDbRefuses(fn () => $line->update(['offered_quantity' => '5']), 'draft revision');
        $this->assertDbRefuses(fn () => $line->delete(), 'draft revision');
        $this->assertSame('2.0000', $line->fresh()->offered_quantity);
    }

    public function test_a_line_keeps_its_revision_and_scope_and_cannot_move_between_revisions(): void
    {
        $family = $this->family();
        $one = $this->revision($family);
        $two = $this->revision($family);
        $scope = $this->scope($family, 'm');
        $line = $this->line($one, $scope);

        $this->assertDbRefuses(fn () => $line->update(['revision_id' => $two->id]), 'keeps its revision');
        $this->assertDbRefuses(fn () => $line->update(['logical_scope_id' => $this->scope($family, 'n')->id]), 'keeps its revision');
        // The same logical scope in the next revision is the same deliverable.
        $carried = $this->line($two, $scope);
        $this->assertSame($scope->id, $carried->logical_scope_id);
        $this->assertSame($scope->id, QuotationDraftLine::query()->findOrFail($line->id)->logical_scope_id);
    }

    public function test_the_revision_state_cannot_be_issued_and_no_number_or_posting_exists(): void
    {
        $family = $this->family();
        $revision = $this->revision($family);
        $this->line($revision, $this->scope($family, 'n'));
        $before = [DB::table('stock_moves')->count(), DB::table('stock_valuation_entries')->count(), DB::table('numbering_counters')->count(), DB::table('numbering_allocations')->count()];

        $this->assertDbRefuses(fn () => DB::table('quotation_revisions')->where('id', $revision->id)->update(['state' => 'ISSUED']));
        $this->assertDbRefuses(fn () => DB::table('quotation_revisions')->insert(['company_id' => 1, 'family_id' => $family->id, 'state' => 'ISSUED', 'currency_id' => $this->pkr->id, 'created_by' => $this->user->id,
            'created_at' => now(), 'updated_at' => now()]));
        foreach (['official_number', 'numbering_allocation_id', 'issued_at'] as $column) {
            $this->assertFalse(DB::getSchemaBuilder()->hasColumn('quotation_revisions', $column), "{$column} belongs to the issue slice");
        }
        foreach (['quotation_candidates', 'quotation_issues', 'quotation_adoptions', 'quotation_operation_receipts', 'quotation_offer_events'] as $later) {
            $this->assertFalse(DB::getSchemaBuilder()->hasTable($later), "{$later} belongs to a later slice");
        }
        foreach (['entered_unit_price', 'unit_price', 'net_amount', 'cost', 'tax_amount'] as $column) {
            $this->assertFalse(DB::getSchemaBuilder()->hasColumn('quotation_draft_lines', $column), "{$column}: a draft line holds no money in QL0");
        }
        $this->assertSame($before, [DB::table('stock_moves')->count(), DB::table('stock_valuation_entries')->count(), DB::table('numbering_counters')->count(), DB::table('numbering_allocations')->count()]);
    }

    public function test_the_numbering_type_is_registered_for_the_future_issue_and_nothing_consumes_it(): void
    {
        $registry = app(NumberingRegistry::class);

        $this->assertTrue($registry->has('QUOTATION'));
        $type = $registry->get('QUOTATION');
        $this->assertInstanceOf(QuotationNumbering::class, $type);
        $this->assertSame(['sales', 'when the quotation revision is issued', 'sales.numbering.manage'], [$type->module(), $type->becomesOfficial(), $type->managePermission()]);
        $this->revision($this->family());
        $this->assertSame([0, 0], [DB::table('numbering_counters')->count(), DB::table('numbering_allocations')->count()], 'Creating a family and a revision allocates no number.');
    }

    public function test_rolling_the_migration_back_refuses_to_erase_quotation_history(): void
    {
        $migration = require base_path('database/migrations/2026_10_16_120000_create_quotation_foundation.php');
        $this->family();

        $this->assertThrows(fn () => $migration->down(), \RuntimeException::class);
        $this->assertSame(1, QuotationFamily::query()->count());
    }
}
