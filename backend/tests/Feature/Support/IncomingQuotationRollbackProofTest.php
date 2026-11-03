<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Modules\Sales\Actions\UpdateQuotationDraft;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Tax\Actions\CorrectQuotationTaxSource;
use App\Modules\Tax\Actions\DeclareQuotationTaxSource;
use App\Modules\Tax\Support\EffectivePeriod;
use App\Modules\Tax\Support\TaxDutyManifest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Modules\Sales\Concerns\BuildsQuotationOwnership;
use Tests\Support\AssertsPopulatedRollbackRefusal;
use Tests\TestCase;

/** Reconcile incoming pricing/tax migrations with the all-migrations safety inventory; no upstream product edits. */
class IncomingQuotationRollbackProofTest extends TestCase
{
    use AssertsPopulatedRollbackRefusal, BuildsQuotationOwnership, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOwnership();
        $this->activateOwnershipDuties();
    }

    public function test_a_populated_document_discount_preserves_rows_and_triggers_on_rollback(): void
    {
        $family = $this->nativeFamily();
        $revision = $family->revisions()->firstOrFail();
        // Valid storage fixture only: this proves schema backout, not authority to enter a concession.
        DB::table('quotation_draft_document_discounts')->insert(['company_id' => 1, 'revision_id' => $revision->id,
            'kind' => 'AMOUNT', 'value' => '1.0000', 'entered_by' => $this->preparer->id, 'entered_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->assertPopulatedRollbackRefused('2026_11_03_090000_add_quotation_draft_adjustments.php', ['quotation_draft_document_discounts']);
        $this->assertSame('1.0000', DB::table('quotation_draft_document_discounts')->value('value'));
    }

    public function test_a_populated_seller_declaration_preserves_rows_and_triggers_on_rollback(): void
    {
        $family = $this->nativeFamily();
        $revision = $family->revisions()->firstOrFail();
        app(UpdateQuotationDraft::class)($this->preparer, (int) $revision->id, (int) $revision->input_generation, 'rollback-declaration', [
            'add_lines' => [['source_kind' => 'MANUAL', 'description' => 'Synthetic rollback service', 'manual_measure' => 'job', 'offered_quantity' => '1.0000',
                'tax_declaration' => ['nature' => 'SERVICES', 'classification' => 'synthetic', 'reason' => 'Private populated rollback fixture']]],
        ]);
        $this->assertPopulatedRollbackRefused('2026_11_03_110000_create_quotation_draft_tax_declarations.php', ['quotation_draft_tax_declarations']);
        $this->assertSame('synthetic', DB::table('quotation_draft_tax_declarations')->value('classification'));
        $this->assertGreaterThan($revision->input_generation, QuotationRevision::findOrFail($revision->id)->input_generation);
    }

    public function test_a_populated_tax_source_correction_preserves_original_and_correction_on_rollback(): void
    {
        Permission::findOrCreate(TaxDutyManifest::CONFIGURE, 'web');
        $this->preparer->givePermissionTo(TaxDutyManifest::CONFIGURE);
        $this->app->instance(TaxDutyManifest::class, new TaxDutyManifest([TaxDutyManifest::CONFIGURE => 'ACTIVE']));
        $generation = fn (): int => (int) DB::table('tax_generations')->where('company_id', 1)->value('generation');
        $fact = app(DeclareQuotationTaxSource::class)->jurisdiction($this->preparer, 'synthetic-original', new EffectivePeriod('2026-01-01', '2027-01-01'), 'Private rollback fixture', 'rollback-source', $generation());
        app(CorrectQuotationTaxSource::class)->jurisdiction($this->preparer, $fact, 'synthetic-corrected', 'Private correction fixture', 'rollback-correction', $generation());
        $this->assertPopulatedRollbackRefused('2026_11_03_100000_create_tax_quotation_source_corrections.php', ['tax_quotation_source_corrections', 'tax_quotation_jurisdictions']);
        $this->assertSame('synthetic-original', DB::table('tax_quotation_jurisdictions')->where('id', $fact)->value('jurisdiction'));
        $this->assertSame('synthetic-corrected', DB::table('tax_quotation_source_corrections')->value('jurisdiction'));
    }
}
