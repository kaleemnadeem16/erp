<?php

namespace Tests\Feature\Modules\Sales;

use App\Exceptions\DomainInvariantException;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Estimating\Actions\ActivateAssemblyVersion;
use App\Modules\Estimating\Models\Assembly;
use App\Modules\Estimating\Models\AssemblyVersion;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\AvcoPoolStatus;
use App\Modules\Inventory\Support\ItemType;
use App\Modules\Sales\Actions\CreateQuotationDraft;
use App\Modules\Sales\Actions\CreateQuotationRevision;
use App\Modules\Sales\Actions\IssueQuotation;
use App\Modules\Sales\Actions\PreviewQuotation;
use App\Modules\Sales\Actions\ReadQuotationDraftForEdit;
use App\Modules\Sales\Actions\UpdateQuotationDraft;
use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Models\QuotationIssuedLine;
use App\Modules\Sales\Models\QuotationRevision;
use App\Support\UomDimension;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Modules\Sales\Concerns\BuildsIssuableQuotations;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

/**
 * Cross-phase integration, Batch 1 — Assembly work as a quotation line. A seller who holds the quotation preparer duty and NO estimating permission prepares, previews and (through the
 * issuer) issues Assembly lines: the source is one Assembly version pinned by id and kept after a newer version activates, the work quantity is judged at the technical six places then at
 * the four an offer holds (never rounded), and no cost is read, stored or returned. Distinctive cost figures (0.5 kg x 10.0000 + 777.7777 = 782.7777 per unit) make a leak findable.
 */
class AssemblyQuotationLineTest extends TestCase
{
    use BuildsIssuableQuotations;
    use RefreshDatabase;

    private const COST_FIGURES = ['782.7777', '777.7777', '2348.3331', '782.78'];

    private Uom $kg;

    private Uom $sqm;

    private Item $pigment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIssuing();
        $this->kg = Uom::create(['code' => 'kg', 'name' => 'Kilogram', 'dimension' => UomDimension::Mass, 'factor_to_base' => '1', 'is_base' => true]);
        $this->sqm = Uom::create(['code' => 'sqm', 'name' => 'Square metre', 'dimension' => UomDimension::Area, 'factor_to_base' => '1', 'is_base' => true]);
        $category = Category::create(['code' => 'RAW', 'name' => 'Raw', 'default_valuation_method' => 'AVCO']);
        $this->pigment = Item::create(['code' => 'pigment', 'name' => 'Pigment', 'type' => ItemType::Raw, 'stock_uom_id' => $this->kg->id, 'category_id' => $category->id, 'is_sellable' => true, 'is_stocked' => true]);
        AvcoPool::create([
            'item_id' => $this->pigment->id, 'scope' => AvcoPoolScope::CompanyItem, 'qty_on_hand' => '100', 'inventory_value' => '1000', 'avg_unit_cost' => '10.0000',
            'base_avg_unit_cost' => '10.0000', 'last_valid_avg_unit_cost' => '10.0000', 'status' => AvcoPoolStatus::Active,
        ]);
    }

    private function assembly(?Uom $workUnit = null): Assembly
    {
        return Assembly::create(['code' => 'ASM-'.str()->random(6), 'name' => 'Epoxy floor', 'work_uom_id' => ($workUnit ?? $this->sqm)->id, 'margin_type' => 'MARKUP', 'margin_pct' => '20']);
    }

    private function version(Assembly $assembly, int $number = 1, bool $activate = true): AssemblyVersion
    {
        $version = $assembly->versions()->create(['version_no' => $number])->refresh();
        $version->components()->create(['kind' => 'MATERIAL', 'item_id' => $this->pigment->id, 'qty_per_work_unit' => '0.500000', 'uom_id' => $this->kg->id, 'sequence' => 0]);
        $version->components()->create(['kind' => 'SUBCONTRACT', 'qty_per_work_unit' => '1', 'uom_id' => $this->sqm->id, 'rate_override' => '777.7777', 'sequence' => 1]);
        if ($activate) {
            (new ActivateAssemblyVersion)($version, PermissionedActor::grant(User::factory()->create(), ['estimating.assembly.manage']));
        }

        return $version->refresh();
    }

    private function draft(): int
    {
        return app(CreateQuotationDraft::class)($this->maker, $this->issueKey(), ['recipient_party_id' => $this->customer->id, 'pricing_date' => $this->today(), 'tax_estimate_date' => $this->today(),
            'valid_through_local_date' => now()->addMonth()->format('Y-m-d')])->revisionId;
    }

    /** @param array<string, mixed> $line */
    private function add(int $revisionId, array $line, ?User $by = null): void
    {
        app(UpdateQuotationDraft::class)($by ?? $this->maker, $revisionId, QuotationRevision::findOrFail($revisionId)->input_generation, $this->issueKey(), ['add_lines' => [$line]]);
    }

    /** @param array<string, mixed> $change */
    private function change(int $revisionId, int $lineId, array $change): void
    {
        app(UpdateQuotationDraft::class)($this->maker, $revisionId, QuotationRevision::findOrFail($revisionId)->input_generation, $this->issueKey(), ['update_lines' => [['id' => $lineId] + $change]]);
    }

    private function asm(int $versionId, string $quantity = '3', array $more = []): array
    {
        return ['source_kind' => 'ASSEMBLY_WORK', 'assembly_version_id' => $versionId, 'offered_quantity' => $quantity, 'unit_price' => '1500.0000'] + $more;
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

    public function test_a_seller_without_any_estimating_permission_prepares_an_assembly_line_pinned_to_one_version(): void
    {
        $this->assertFalse($this->maker->getAllPermissions()->contains(fn ($p): bool => str_starts_with($p->name, 'estimating.')));
        $version = $this->version($this->assembly());
        $revision = $this->draft();

        $this->add($revision, $this->asm($version->id, '3'));

        $line = QuotationDraftLine::query()->where('revision_id', $revision)->sole();
        $this->assertSame(['ASSEMBLY_WORK', $version->id, null, null], [$line->source_kind, $line->assembly_version_id, $line->item_id, $line->manual_measure]);
        $this->assertSame([$this->sqm->id, '3.0000'], [$line->offered_uom_id, (string) $line->offered_quantity], 'sold in the Assembly work unit, named or not');
        $this->assertStringContainsString('Epoxy floor', $line->description);
        $this->assertNull($line->priced_quantity, 'an Assembly line has no stock measure');
        $edit = app(ReadQuotationDraftForEdit::class)($this->maker, $revision);
        $this->assertSame($version->id, (int) $edit->lines->sole()->assembly_version_id);
    }

    public function test_the_draft_the_preview_and_the_edit_view_carry_no_cost_for_a_cost_blind_seller(): void
    {
        $version = $this->version($this->assembly());
        $revision = $this->draft();
        $this->add($revision, $this->asm($version->id, '3'));

        $preview = app(PreviewQuotation::class)($this->maker, $revision);

        $line = $preview['lines'][0];
        $this->assertSame($version->id, $line['assembly_version_id']);
        $this->assertSame('COMPLETE', $line['net']['certainty'], 'the settled net needs the price and quantity, not the cost');
        $this->assertSame('4500.0000', $line['net']['amount']);
        $this->assertSame([], $line['issues']);
        $everything = json_encode([$preview, app(ReadQuotationDraftForEdit::class)($this->maker, $revision)->toArray(), DB::table('quotation_draft_lines')->where('revision_id', $revision)->get()]);
        foreach (self::COST_FIGURES as $secret) {
            $this->assertStringNotContainsString($secret, $everything, "cost figure {$secret} must not appear");
        }
    }

    public function test_every_unsupported_assembly_source_is_refused_and_saves_nothing(): void
    {
        $revision = $this->draft();
        $active = $this->version($this->assembly());
        $draftVersion = $this->version($this->assembly(), 1, false);
        $item = $this->pigment;
        $each = [
            'no version named' => [['source_kind' => 'ASSEMBLY_WORK', 'offered_quantity' => '1'], 'INVALID_INPUT'],
            'unknown version' => [$this->asm(987654), 'SOURCE_NOT_FOUND'],
            'a draft version' => [$this->asm($draftVersion->id), 'SOURCE_INCOMPATIBLE'],
            'another unit' => [$this->asm($active->id, '1', ['offered_uom_id' => $this->kg->id]), 'SELLING_UNIT_UNSUPPORTED'],
            'an item as well' => [$this->asm($active->id, '1', ['item_id' => $item->id]), 'INVALID_INPUT'],
            'a manual measure as well' => [$this->asm($active->id, '1', ['manual_measure' => 'job']), 'INVALID_INPUT'],
            'a version on an item line' => [['source_kind' => 'DIRECT_ITEM', 'item_id' => $item->id, 'assembly_version_id' => $active->id, 'offered_quantity' => '1'], 'INVALID_INPUT'],
            'zero' => [$this->asm($active->id, '0'), 'QUANTITY_ZERO'],
            'negative' => [$this->asm($active->id, '-1'), 'QUANTITY_NEGATIVE'],
        ];
        foreach ($each as $label => [$line, $code]) {
            $this->assertSame($code, $this->refusal(fn () => $this->add($revision, $line)), $label);
        }
        $this->assertSame(0, QuotationDraftLine::query()->count());
    }

    public function test_the_technical_quantity_is_six_places_and_the_offer_four_and_nothing_is_rounded(): void
    {
        $version = $this->version($this->assembly());
        $revision = $this->draft();

        // Four places fit both scales and are stored exactly.
        $this->add($revision, $this->asm($version->id, '2.1235'));
        $this->assertSame('2.1235', (string) QuotationDraftLine::query()->sole()->offered_quantity);
        QuotationDraftLine::query()->delete();

        // Five and six places are valid technical measures but cannot be offered: refused with the shared stable code, never rounded.
        foreach (['2.12345', '0.000001'] as $fine) {
            $this->assertSame('QUANTITY_NOT_REPRESENTABLE', $this->refusal(fn () => $this->add($revision, $this->asm($version->id, $fine))), $fine);
        }
        // Seven places are not even a technical measure.
        $this->assertSame('QUANTITY_NOT_REPRESENTABLE', $this->refusal(fn () => $this->add($revision, $this->asm($version->id, '1.1234567'))));
        $this->assertSame(0, QuotationDraftLine::query()->count());
    }

    public function test_the_stored_version_survives_a_newer_activation_and_is_never_silently_replaced(): void
    {
        $assembly = $this->assembly();
        $first = $this->version($assembly, 1);
        $revision = $this->draft();
        $this->add($revision, $this->asm($first->id, '3'));
        $lineId = QuotationDraftLine::query()->sole()->id;

        $second = $this->version($assembly, 2);
        $this->assertNotSame($first->id, $second->id);

        $this->assertSame($first->id, QuotationDraftLine::findOrFail($lineId)->assembly_version_id, 'the line still names the version it was prepared from');
        $preview = app(PreviewQuotation::class)($this->maker, $revision);
        $this->assertSame([$first->id, ['SOURCE_INCOMPATIBLE']], [$preview['lines'][0]['assembly_version_id'], $preview['lines'][0]['issues']], 'a superseded version is reported, never swapped for the newer one');
        $this->assertSame('SOURCE_INCOMPATIBLE', $this->refusal(fn () => $this->change($revision, $lineId, ['offered_quantity' => '4'])), 'editing the quantity does not quietly re-pin it');
        $this->assertSame($first->id, QuotationDraftLine::findOrFail($lineId)->assembly_version_id);

        // The seller moves to the current version deliberately.
        $this->change($revision, $lineId, ['assembly_version_id' => $second->id, 'offered_quantity' => '4']);
        $this->assertSame([$second->id, '4.0000'], [QuotationDraftLine::findOrFail($lineId)->assembly_version_id, (string) QuotationDraftLine::findOrFail($lineId)->offered_quantity]);
    }

    public function test_an_issued_line_freezes_its_version_and_keeps_it_after_a_newer_one_activates(): void
    {
        $assembly = $this->assembly();
        $first = $this->version($assembly, 1);
        $revision = $this->draft();
        $this->add($revision, $this->asm($first->id, '3'));
        $generation = QuotationRevision::findOrFail($revision)->input_generation;

        app(IssueQuotation::class)($this->checker, $revision, $generation, $this->issueKey());
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->version($assembly, 2);

        $issued = QuotationIssuedLine::query()->where('revision_id', $revision)->sole();
        $this->assertSame(['ASSEMBLY_WORK', $first->id, null], [$issued->source_kind, $issued->assembly_version_id, $issued->item_id]);
        $this->assertSame('SUPERSEDED', AssemblyVersion::findOrFail($first->id)->status->value, 'the sold version is history, still identifiable');
        $this->expectException(QueryException::class);
        DB::table('quotation_issued_lines')->where('id', $issued->id)->update(['assembly_version_id' => null]);
    }

    public function test_a_successor_draft_keeps_the_pinned_version_of_an_issued_assembly_line(): void
    {
        $assembly = $this->assembly();
        $first = $this->version($assembly, 1);
        $revision = $this->draft();
        $this->add($revision, $this->asm($first->id, '3'));
        app(IssueQuotation::class)($this->checker, $revision, QuotationRevision::findOrFail($revision)->input_generation, $this->issueKey());
        $this->version($assembly, 2);

        $successor = app(CreateQuotationRevision::class)($this->maker, $revision, $this->issueKey());

        $copy = QuotationDraftLine::query()->where('revision_id', $successor->revisionId)->sole();
        $this->assertSame($first->id, $copy->assembly_version_id);
        $this->assertSame(['SOURCE_INCOMPATIBLE'], app(PreviewQuotation::class)($this->maker, $successor->revisionId)['lines'][0]['issues'], 'the superseded version must be chosen again on purpose');
    }

    public function test_the_database_refuses_a_malformed_assembly_line_or_an_issued_line_that_disagrees_with_its_draft(): void
    {
        $version = $this->version($this->assembly());
        $revision = $this->draft();
        $this->add($revision, $this->asm($version->id, '3'));
        $line = QuotationDraftLine::query()->sole();
        $row = ['company_id' => 1, 'family_id' => $line->family_id, 'revision_id' => $revision, 'logical_scope_id' => $line->logical_scope_id + 1000, 'description' => 'x', 'source_kind' => 'ASSEMBLY_WORK', 'offered_quantity' => 1, 'offered_uom_id' => $this->sqm->id, 'created_by' => $this->maker->id];

        foreach (['no version' => ['assembly_version_id' => null], 'with an item' => ['assembly_version_id' => $version->id, 'item_id' => $this->pigment->id]] as $label => $bad) {
            try {
                DB::transaction(fn () => DB::table('quotation_draft_lines')->insert($bad + $row));
                $this->fail("the database accepted an Assembly line {$label}");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
