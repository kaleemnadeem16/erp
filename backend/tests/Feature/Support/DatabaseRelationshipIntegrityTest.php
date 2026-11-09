<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Modules\Sales\Actions\UpdateQuotationDraft;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Tax\Actions\CorrectQuotationTaxSource;
use App\Modules\Tax\Actions\DeclareQuotationTaxSource;
use App\Modules\Tax\Support\EffectivePeriod;
use App\Modules\Tax\Support\TaxDutyManifest;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Modules\Sales\Concerns\BuildsQuotationOwnership;
use Tests\TestCase;

/**
 * Issue #99 (database integrity): every case here goes straight to SQL, so it proves the DATABASE refuses the bad row, whatever any PHP Action would have done first.
 */
class DatabaseRelationshipIntegrityTest extends TestCase
{
    use BuildsQuotationOwnership, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOwnership();
        $this->activateOwnershipDuties();
    }

    /** @param callable(): mixed $statement */
    private function assertRefusedBy(string $needle, callable $statement): void
    {
        try {
            DB::transaction($statement);
        } catch (QueryException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());

            return;
        }
        $this->fail("The database accepted a row that '{$needle}' must refuse.");
    }

    /** @return array{revision: int, scope: int, family: int} */
    private function draftWithLine(bool $declare): array
    {
        $family = $this->nativeFamily();
        $revision = $family->revisions()->firstOrFail();
        $line = ['source_kind' => 'MANUAL', 'description' => 'Synthetic service', 'manual_measure' => 'job', 'offered_quantity' => '1.0000'];
        if ($declare) {
            $line['tax_declaration'] = ['nature' => 'SERVICES', 'classification' => 'synthetic', 'reason' => 'Private integrity fixture'];
        }
        app(UpdateQuotationDraft::class)($this->preparer, (int) $revision->id, (int) $revision->input_generation, $this->key(), ['add_lines' => [$line]]);

        return ['revision' => (int) $revision->id, 'scope' => (int) DB::table('quotation_draft_lines')->where('revision_id', $revision->id)->value('logical_scope_id'), 'family' => (int) $family->id];
    }

    private function declaration(int $revision, int $scope, int $version, array $extra = []): array
    {
        return $extra + ['company_id' => 1, 'revision_id' => $revision, 'logical_scope_id' => $scope, 'version_no' => $version, 'nature' => 'GOODS', 'classification' => 'raw', 'reason' => 'Raw SQL probe', 'declared_by' => $this->preparer->id];
    }

    public function test_a_tax_declaration_cannot_pair_a_revision_with_a_scope_of_another_family(): void
    {
        $a = $this->draftWithLine(true);
        $b = $this->draftWithLine(false);
        $this->assertSame($a['family'], (int) DB::table('quotation_draft_tax_declarations')->where('revision_id', $a['revision'])->value('family_id'), 'the guard derives the family from the revision');

        $this->assertRefusedBy('quotation_tax_declarations_family_scope_fk', fn () => DB::table('quotation_draft_tax_declarations')->insert($this->declaration($a['revision'], $b['scope'], 1)));
        // Supplying a family that is not the revision's is refused too (revision key).
        $this->assertRefusedBy('quotation_tax_declarations_family_', fn () => DB::table('quotation_draft_tax_declarations')->insert($this->declaration($a['revision'], $a['scope'], 2, ['family_id' => $b['family']])));
        // The honest row is still accepted.
        DB::table('quotation_draft_tax_declarations')->insert($this->declaration($a['revision'], $a['scope'], 2));
        $this->assertSame(2, DB::table('quotation_draft_tax_declarations')->where('revision_id', $a['revision'])->count());
    }

    public function test_a_customer_decision_receipt_must_name_an_existing_case_of_its_own_company(): void
    {
        $receipt = fn (int $company, int $case, int $actor, string $key): array => ['company_id' => $company, 'actor_id' => $actor, 'request_key' => $key, 'operation' => 'probe', 'case_id' => $case,
            'payload_hash' => str_repeat('a', 64), 'completed_at' => now(), 'result' => '{}'];
        $this->assertRefusedBy('must name a case of its own company', fn () => DB::table('sales_customer_decision_receipts')->insert($receipt(1, 987654, $this->preparer->id, 'k1')));

        $case = DB::table('customer_decision_cases')->insertGetId(['company_id' => 1, 'party_id' => $this->prospect->id, 'intake_identity' => 'probe-case', 'recorded_by' => $this->preparer->id, 'recorded_at' => now()]);
        // Another company's actor cannot borrow this company's case.
        $this->assertRefusedBy('must name a case of its own company', fn () => DB::table('sales_customer_decision_receipts')->insert($receipt($this->otherCompany, $case, $this->otherUser, 'k2')));
        DB::table('sales_customer_decision_receipts')->insert($receipt(1, $case, $this->preparer->id, 'k3'));
        $this->assertSame(1, DB::table('sales_customer_decision_receipts')->count());
    }

    public function test_a_tax_source_correction_must_follow_the_chain_of_its_own_fact(): void
    {
        Permission::findOrCreate(TaxDutyManifest::CONFIGURE, 'web');
        $this->preparer->givePermissionTo(TaxDutyManifest::CONFIGURE);
        $this->app->instance(TaxDutyManifest::class, new TaxDutyManifest([TaxDutyManifest::CONFIGURE => 'ACTIVE']));
        $generation = fn (): int => (int) DB::table('tax_generations')->where('company_id', 1)->value('generation');
        $declare = app(DeclareQuotationTaxSource::class);
        $f1 = $declare->jurisdiction($this->preparer, 'one', new EffectivePeriod('2026-01-01', '2026-07-01'), 'Fixture', 'src-1', $generation());
        $f2 = $declare->jurisdiction($this->preparer, 'two', new EffectivePeriod('2026-07-01', '2027-01-01'), 'Fixture', 'src-2', $generation());
        $correct = app(CorrectQuotationTaxSource::class);
        $correct->jurisdiction($this->preparer, $f1, 'one-b', 'Fixture', 'cor-1', $generation());
        $correct->jurisdiction($this->preparer, $f2, 'two-b', 'Fixture', 'cor-2', $generation());
        $c1 = (int) DB::table('tax_quotation_source_corrections')->where('jurisdiction_fact_id', $f1)->value('id');
        $c2 = (int) DB::table('tax_quotation_source_corrections')->where('jurisdiction_fact_id', $f2)->value('id');

        $row = fn (int $fact, int $version, ?int $predecessor): array => ['company_id' => 1, 'jurisdiction_fact_id' => $fact, 'version_no' => $version, 'predecessor_id' => $predecessor,
            'jurisdiction' => 'probe', 'reason' => 'Raw SQL probe', 'created_by' => $this->preparer->id];
        // The predecessor belongs to another fact.
        $this->assertRefusedBy('same fact', fn () => DB::table('tax_quotation_source_corrections')->insert($row($f1, 2, $c2)));
        // A version is skipped.
        $this->assertRefusedBy('next version', fn () => DB::table('tax_quotation_source_corrections')->insert($row($f1, 3, $c1)));
        // A second "first" version of an already corrected fact.
        $this->assertRefusedBy('starts once', fn () => DB::table('tax_quotation_source_corrections')->insert($row($f1, 1, null)));
        // The honest next version is accepted, and then cannot be forked.
        DB::table('tax_quotation_source_corrections')->insert($row($f1, 2, $c1));
        $this->assertRefusedBy('tax_quote_correction_jurisdiction_version', fn () => DB::table('tax_quotation_source_corrections')->insert($row($f1, 2, $c1)));
    }

    public function test_a_brand_version_cannot_name_a_logo_without_its_pinned_details(): void
    {
        $blob = DB::table('file_blobs')->insertGetId(['company_id' => 1, 'storage_disk' => 'files', 'storage_key' => str_repeat('a', 40), 'sha256' => str_repeat('b', 64), 'size_bytes' => 10,
            'detected_media_type' => 'image/png', 'health' => 'VERIFIED', 'scan_state' => 'NOT_CONFIGURED', 'created_by' => $this->preparer->id, 'created_at' => now()]);
        $full = ['company_id' => 1, 'version' => 1, 'logo_blob_id' => $blob, 'logo_sha256' => str_repeat('b', 64), 'logo_media_type' => 'image/png', 'logo_width' => 2, 'logo_height' => 2,
            'logo_size_bytes' => 10, 'reason' => 'Raw SQL probe', 'created_by' => $this->preparer->id, 'created_at' => now()];
        foreach (['logo_sha256', 'logo_media_type', 'logo_width', 'logo_height', 'logo_size_bytes'] as $missing) {
            $this->assertRefusedBy('company_brand_versions_', fn () => DB::table('company_brand_versions')->insert([$missing => null] + $full));
        }
        // A detail without the blob is refused as well.
        $this->assertRefusedBy('company_brand_versions_', fn () => DB::table('company_brand_versions')->insert(['logo_blob_id' => null] + $full));
        DB::table('company_brand_versions')->insert($full);
        $this->assertSame(1, DB::table('company_brand_versions')->count());
    }

    public function test_the_new_constraints_are_validated_and_the_migrations_roll_back_and_forward_cleanly(): void
    {
        $unvalidated = DB::table('pg_constraint')->whereIn('conname', ['quotation_tax_declarations_family_revision_fk', 'quotation_tax_declarations_family_scope_fk', 'company_brand_versions_logo_complete'])
            ->where('convalidated', false)->pluck('conname')->all();
        $this->assertSame([], $unvalidated, 'a fresh database validates every new constraint');

        $files = ['2026_12_10_330000_require_complete_brand_logo_metadata.php', '2026_12_10_320000_guard_tax_source_correction_chain.php', '2026_12_10_310000_guard_customer_decision_receipt_case.php', '2026_12_10_300000_require_declaration_revision_and_scope_in_one_family.php'];
        $a = $this->draftWithLine(true);
        // The test's own open transaction still holds queued foreign-key checks from the fixture rows; a real migration starts clean.
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::transaction(function () use ($files): void {
            foreach ($files as $file) {
                (require database_path('migrations/'.$file))->down();
            }
            $this->assertSame(0, DB::table('pg_constraint')->where('conname', 'quotation_tax_declarations_family_scope_fk')->count());
            $this->assertFalse(Schema::hasColumn('quotation_draft_tax_declarations', 'family_id'));
            foreach (array_reverse($files) as $file) {
                (require database_path('migrations/'.$file))->up();
            }
        });
        $this->assertSame($a['family'], (int) DB::table('quotation_draft_tax_declarations')->where('revision_id', $a['revision'])->value('family_id'), 'the family is back-filled on re-apply');
        $this->assertSame(1, DB::table('pg_constraint')->where('conname', 'quotation_tax_declarations_family_scope_fk')->where('convalidated', true)->count());
        $this->assertRefusedBy('append-only', fn () => DB::table('quotation_draft_tax_declarations')->update(['reason' => 'rewritten']));
        $this->assertSame($a['revision'], (int) QuotationRevision::query()->whereKey($a['revision'])->value('id'));
    }
}
