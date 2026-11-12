<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Modules\Sales\Actions\UpdateQuotationDraft;
use App\Modules\Tax\Actions\CorrectQuotationTaxSource;
use App\Modules\Tax\Actions\DeclareQuotationTaxSource;
use App\Modules\Tax\Support\EffectivePeriod;
use App\Modules\Tax\Support\TaxDutyManifest;
use App\Support\Integrity\HistoricalViolationRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Modules\Sales\Concerns\BuildsQuotationOwnership;
use Tests\TestCase;

/**
 * Issue #107 / ADR 0180 addendum. Historical violations are seeded through a controlled path only: the rule is switched off for one statement inside the test's transaction (a trigger
 * disabled, or a constraint dropped and re-added NOT VALID, exactly as the migrations leave it on a database that had bad rows), the row is inserted, and the rule is restored. New
 * invalid writes stay refused: DatabaseRelationshipIntegrityTest proves that.
 */
class HistoricalIntegrityViolationsTest extends TestCase
{
    use BuildsQuotationOwnership, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOwnership();
        $this->activateOwnershipDuties();
    }

    /** @return array{exit: int, output: string} */
    private function run_(string $command, array $args = []): array
    {
        $exit = Artisan::call($command, $args);

        return ['exit' => $exit, 'output' => Artisan::output()];
    }

    /** Insert one row that the named NOT VALID-able constraint would refuse, then leave the constraint NOT VALID (as on an upgraded database). */
    private function insertPastConstraint(string $table, string $constraint, callable $insert): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $definition = DB::selectOne('SELECT pg_get_constraintdef(oid) AS d FROM pg_constraint WHERE conname = ? AND conrelid = ?::regclass', [$constraint, $table])->d;
        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$constraint}");
        DB::statement("ALTER TABLE {$table} DISABLE TRIGGER USER");
        $insert();
        DB::statement("ALTER TABLE {$table} ENABLE TRIGGER USER");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraint} ".preg_replace('/ NOT VALID$/', '', $definition).' NOT VALID');
    }

    private function brandVersionWithoutHash(): int
    {
        $blob = DB::table('file_blobs')->insertGetId(['company_id' => 1, 'storage_disk' => 'files', 'storage_key' => str_repeat('a', 40), 'sha256' => str_repeat('b', 64), 'size_bytes' => 10,
            'detected_media_type' => 'image/png', 'health' => 'VERIFIED', 'scan_state' => 'NOT_CONFIGURED', 'created_by' => $this->preparer->id, 'created_at' => now()]);
        $id = 0;
        $this->insertPastConstraint('company_brand_versions', 'company_brand_versions_logo_complete', function () use ($blob, &$id): void {
            $id = DB::table('company_brand_versions')->insertGetId(['company_id' => 1, 'version' => 1, 'logo_blob_id' => $blob, 'logo_sha256' => null, 'logo_media_type' => 'image/png', 'logo_width' => 2,
                'logo_height' => 2, 'logo_size_bytes' => 10, 'reason' => 'Private fixture reason', 'created_by' => $this->preparer->id, 'created_at' => now()]);
        });

        return $id;
    }

    public function test_a_clean_database_reports_nothing_and_exits_zero(): void
    {
        $result = $this->run_('integrity:historical-violations');

        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('No historical violations remain.', $result['output']);
        $this->assertSame(4, DB::table('integrity_rule_baselines')->count());
        $this->assertSame(0, (int) DB::table('integrity_rule_baselines')->sum('violations_at_introduction'));
    }

    public function test_seeded_brand_violation_is_counted_listed_by_id_only_and_makes_the_command_fail(): void
    {
        $id = $this->brandVersionWithoutHash();
        $before = DB::table('company_brand_versions')->get()->all();

        $result = $this->run_('integrity:historical-violations', ['--ids' => true]);

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('company_brand_versions_logo_complete', $result['output']);
        $this->assertStringContainsString("company_brand_versions_logo_complete ids: {$id}", $result['output']);
        $this->assertStringNotContainsString('Private fixture reason', $result['output']);
        $this->assertStringNotContainsString(str_repeat('b', 64), $result['output']);
        $this->assertEquals($before, DB::table('company_brand_versions')->get()->all(), 'the command is read only');

        $json = json_decode($this->run_('integrity:historical-violations', ['--json' => true])['output'], true);
        $this->assertSame(1, $json['remaining']);
        $this->assertNull(collect($json['rules'])->firstWhere('rule', 'company_brand_versions_logo_complete')['ids']);
    }

    public function test_validation_is_refused_while_a_violation_remains_and_succeeds_once_the_operator_has_cleaned_it(): void
    {
        $id = $this->brandVersionWithoutHash();
        $this->assertSame(1, DB::table('pg_constraint')->where('conname', 'company_brand_versions_logo_complete')->where('convalidated', false)->count());

        $refused = $this->run_('integrity:validate-constraints');
        $this->assertSame(1, $refused['exit']);
        $this->assertStringContainsString('1 violating row(s) remain', $refused['output']);
        $this->assertSame(1, DB::table('pg_constraint')->where('conname', 'company_brand_versions_logo_complete')->where('convalidated', false)->count());
        $this->assertSame(1, DB::table('company_brand_versions')->where('id', $id)->count(), 'nothing is repaired or deleted');

        // Stand-in for an operator's separately reviewed correction (the table is append-only, so the test lifts that guard for this one statement; the command never does).
        DB::statement('ALTER TABLE company_brand_versions DISABLE TRIGGER USER');
        DB::table('company_brand_versions')->where('id', $id)->update(['logo_sha256' => str_repeat('b', 64)]);
        DB::statement('ALTER TABLE company_brand_versions ENABLE TRIGGER USER');

        $this->assertSame(0, $this->run_('integrity:historical-violations')['exit']);
        $done = $this->run_('integrity:validate-constraints');
        $this->assertSame(0, $done['exit']);
        $this->assertStringContainsString('company_brand_versions_logo_complete: validated.', $done['output']);
        $this->assertSame(1, DB::table('pg_constraint')->where('conname', 'company_brand_versions_logo_complete')->where('convalidated', true)->count());
        $this->assertNotNull(DB::table('integrity_rule_baselines')->where('rule_key', 'company_brand_versions_logo_complete')->value('validated_at'));
    }

    public function test_constraint_state_distinguishes_missing_other_table_unvalidated_and_validated(): void
    {
        $key = 'company_brand_versions_logo_complete';
        $this->assertSame('validated', HistoricalViolationRegistry::constraintState($key));
        $this->assertSame('guard', HistoricalViolationRegistry::constraintState('sales_customer_decision_receipt_case'));

        $definition = DB::selectOne("SELECT pg_get_constraintdef(oid) AS d FROM pg_constraint WHERE conname = ? AND conrelid = 'company_brand_versions'::regclass", [$key])->d;
        DB::statement("ALTER TABLE company_brand_versions DROP CONSTRAINT {$key}");

        // Missing.
        $this->assertSame('missing', HistoricalViolationRegistry::constraintState($key));
        $missing = $this->run_('integrity:validate-constraints', ['rule' => $key]);
        $this->assertSame(1, $missing['exit']);
        $this->assertStringContainsString('MISSING', $missing['output']);
        $this->assertStringNotContainsString('already validated', $missing['output']);

        // Same name, validated, on a different table: still missing for the expected table.
        DB::statement('CREATE TEMPORARY TABLE other_table_for_107 (id int, a int)');
        DB::statement("ALTER TABLE other_table_for_107 ADD CONSTRAINT {$key} CHECK (a IS NULL OR a > 0)");
        $this->assertSame('missing', HistoricalViolationRegistry::constraintState($key));
        $this->assertSame(1, $this->run_('integrity:validate-constraints', ['rule' => $key])['exit']);

        // Present but NOT VALID on the expected table.
        DB::statement("ALTER TABLE company_brand_versions ADD CONSTRAINT {$key} ".preg_replace('/ NOT VALID$/', '', $definition).' NOT VALID');
        $this->assertSame('not_validated', HistoricalViolationRegistry::constraintState($key));
        $this->assertTrue(HistoricalViolationRegistry::isNotValidated($key));

        // Validated.
        $done = $this->run_('integrity:validate-constraints', ['rule' => $key]);
        $this->assertSame(0, $done['exit']);
        $this->assertSame('validated', HistoricalViolationRegistry::constraintState($key));
        $again = $this->run_('integrity:validate-constraints', ['rule' => $key]);
        $this->assertSame(0, $again['exit']);
        $this->assertStringContainsString('already validated', $again['output']);
    }

    public function test_unknown_rule_is_refused_and_guards_have_nothing_to_validate(): void
    {
        $this->assertSame(1, $this->run_('integrity:validate-constraints', ['rule' => 'nope'])['exit']);
        $guard = $this->run_('integrity:validate-constraints', ['rule' => 'sales_customer_decision_receipt_case']);
        $this->assertSame(0, $guard['exit']);
        $this->assertStringContainsString('nothing to validate', $guard['output']);
    }

    public function test_an_orphan_receipt_and_a_cross_family_declaration_are_counted(): void
    {
        $family = $this->nativeFamily();
        $revision = $family->revisions()->firstOrFail();
        $line = ['source_kind' => 'MANUAL', 'description' => 'Synthetic service', 'manual_measure' => 'job', 'offered_quantity' => '1.0000'];
        app(UpdateQuotationDraft::class)($this->preparer, (int) $revision->id, (int) $revision->input_generation, $this->key(), ['add_lines' => [$line]]);
        $other = $this->nativeFamily()->revisions()->firstOrFail();
        app(UpdateQuotationDraft::class)($this->preparer, (int) $other->id, (int) $other->input_generation, $this->key(), ['add_lines' => [$line]]);
        $scope = (int) DB::table('quotation_draft_lines')->where('revision_id', $other->id)->value('logical_scope_id'); // a scope of ANOTHER family
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('ALTER TABLE sales_customer_decision_receipts DISABLE TRIGGER USER');
        DB::table('sales_customer_decision_receipts')->insert(['company_id' => 1, 'actor_id' => $this->preparer->id, 'request_key' => 'hist-1', 'operation' => 'probe', 'case_id' => 987654,
            'payload_hash' => str_repeat('a', 64), 'completed_at' => now(), 'result' => '{}']);
        DB::statement('ALTER TABLE sales_customer_decision_receipts ENABLE TRIGGER USER');
        $this->insertPastConstraint('quotation_draft_tax_declarations', 'quotation_tax_declarations_family_scope_fk', function () use ($revision, $scope, $family): void {
            DB::table('quotation_draft_tax_declarations')->insert(['company_id' => 1, 'revision_id' => $revision->id, 'logical_scope_id' => $scope, 'family_id' => $family->id, 'version_no' => 1,
                'nature' => 'GOODS', 'classification' => 'raw', 'reason' => 'Private fixture reason', 'declared_by' => $this->preparer->id, 'declared_at' => now()]);
        });

        $json = json_decode($this->run_('integrity:historical-violations', ['--json' => true, '--ids' => true])['output'], true);
        $byRule = collect($json['rules'])->keyBy('rule');
        $this->assertSame(1, $byRule['sales_customer_decision_receipt_case']['current']);
        $this->assertSame(1, $byRule['quotation_tax_declarations_family_scope_fk']['current']);
        $this->assertCount(1, $byRule['quotation_tax_declarations_family_scope_fk']['ids']);
        $this->assertSame(2, $json['remaining']);
    }

    public function test_a_broken_correction_chain_is_counted(): void
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
        $c2 = (int) DB::table('tax_quotation_source_corrections')->where('jurisdiction_fact_id', $f2)->value('id');

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('ALTER TABLE tax_quotation_source_corrections DISABLE TRIGGER USER');
        // Version 2 of fact one names the correction of fact two as its predecessor: the chain rule would have refused it.
        $bad = DB::table('tax_quotation_source_corrections')->insertGetId(['company_id' => 1, 'jurisdiction_fact_id' => $f1, 'version_no' => 2, 'predecessor_id' => $c2,
            'jurisdiction' => 'probe', 'reason' => 'Private fixture reason', 'created_by' => $this->preparer->id]);
        DB::statement('ALTER TABLE tax_quotation_source_corrections ENABLE TRIGGER USER');

        $result = $this->run_('integrity:historical-violations', ['--ids' => true]);
        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString("tax_quote_correction_chain ids: {$bad}", $result['output']);
    }

    public function test_the_migration_counts_existing_violations_records_them_and_logs_a_warning(): void
    {
        $this->brandVersionWithoutHash();
        $migration = require database_path('migrations/2026_12_10_340000_record_historical_integrity_violations.php');
        Log::spy();

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $migration->down();
        $migration->up();

        $this->assertSame(1, (int) DB::table('integrity_rule_baselines')->where('rule_key', 'company_brand_versions_logo_complete')->value('violations_at_introduction'));
        $this->assertNull(DB::table('integrity_rule_baselines')->where('rule_key', 'company_brand_versions_logo_complete')->value('validated_at'));
        $this->assertNotNull(DB::table('integrity_rule_baselines')->where('rule_key', 'quotation_tax_declarations_family_scope_fk')->value('validated_at'));
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $m): bool => str_contains($m, 'company_brand_versions_logo_complete') && str_contains($m, '1 existing row'));
    }
}
