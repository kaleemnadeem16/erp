<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Issue #107 decision D5: the baseline migration must end correct on (a) a fresh install (current 340000) and (b) a database where the ORIGINAL 340000 (commit 6950c433, kept as a
 * fixture) already ran, repaired by the forward migration 2026_12_12_200000.
 */
class HistoricalIntegrityBaselineMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const FK = 'quotation_tax_declarations_family_scope_fk';

    private const CHECK = 'company_brand_versions_logo_complete';

    private function migration(string $path): Migration
    {
        return require $path;
    }

    private function current(): Migration
    {
        return $this->migration(database_path('migrations/2026_12_10_340000_record_historical_integrity_violations.php'));
    }

    private function original(): Migration
    {
        return $this->migration(base_path('tests/Fixtures/migrations/original_6950c433_record_historical_integrity_violations.php'));
    }

    private function forward(): Migration
    {
        return $this->migration(database_path('migrations/2026_12_12_200000_rederive_integrity_rule_baseline_validation_state.php'));
    }

    private function validatedAt(string $key): mixed
    {
        return DB::table('integrity_rule_baselines')->where('rule_key', $key)->value('validated_at');
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return DB::table('integrity_rule_baselines')->orderBy('rule_key')->get()->map(fn (object $r): array => (array) $r)->all();
    }

    private function dropFk(): void
    {
        DB::statement('ALTER TABLE quotation_draft_tax_declarations DROP CONSTRAINT '.self::FK);
    }

    private function makeCheckNotValid(): void
    {
        $def = DB::selectOne('SELECT pg_get_constraintdef(oid) AS d FROM pg_constraint WHERE conname = ? AND conrelid = ?::regclass', [self::CHECK, 'company_brand_versions'])->d;
        DB::statement('ALTER TABLE company_brand_versions DROP CONSTRAINT '.self::CHECK);
        DB::statement('ALTER TABLE company_brand_versions ADD CONSTRAINT '.self::CHECK.' '.preg_replace('/ NOT VALID$/', '', $def).' NOT VALID');
    }

    public function test_fresh_install_records_validated_only_for_validated_constraints(): void
    {
        $this->assertNotNull($this->validatedAt(self::FK));
        $this->assertNotNull($this->validatedAt(self::CHECK));
        $this->assertNull($this->validatedAt('sales_customer_decision_receipt_case'));
        $this->assertNull($this->validatedAt('tax_quote_correction_chain'));

        $this->dropFk();
        $this->makeCheckNotValid();
        $m = $this->current();
        $m->down();
        $m->up();

        $this->assertNull($this->validatedAt(self::FK), 'missing constraint must not be recorded as validated');
        $this->assertNull($this->validatedAt(self::CHECK), 'NOT VALID constraint must not be recorded as validated');
    }

    public function test_fresh_install_then_forward_migration_changes_nothing(): void
    {
        $before = $this->snapshot();
        $this->forward()->up();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_the_original_migration_wrongly_recorded_a_missing_constraint_as_validated(): void
    {
        $this->dropFk();
        $original = $this->original();
        $original->down();
        $original->up();

        $this->assertNotNull($this->validatedAt(self::FK), 'documents the original defect: missing constraint carried validated_at');
    }

    public function test_forward_migration_repairs_a_database_the_original_migration_ran_on(): void
    {
        $this->dropFk();
        $this->makeCheckNotValid();
        $original = $this->original();
        $original->down();
        $original->up();
        $this->assertNotNull($this->validatedAt(self::FK));
        // A guard rule (no constraint) carrying a stray timestamp must end without one.
        DB::table('integrity_rule_baselines')->where('rule_key', 'sales_customer_decision_receipt_case')->update(['validated_at' => now()]);
        $recorded = $this->snapshot();

        $forward = $this->forward();
        $forward->up();

        $this->assertNull($this->validatedAt(self::FK));
        $this->assertNull($this->validatedAt(self::CHECK));
        $this->assertNull($this->validatedAt('sales_customer_decision_receipt_case'), 'a guard rule has no constraint to be validated');
        foreach ($this->snapshot() as $i => $row) {
            $this->assertSame($recorded[$i]['rule_key'], $row['rule_key']);
            $this->assertSame($recorded[$i]['violations_at_introduction'], $row['violations_at_introduction']);
            $this->assertSame($recorded[$i]['recorded_at'], $row['recorded_at']);
        }
        $this->assertSame(4, DB::table('integrity_rule_baselines')->count());

        $after = $this->snapshot();
        $forward->up();
        $this->assertSame($after, $this->snapshot(), 'idempotent');
    }

    public function test_forward_migration_keeps_an_existing_timestamp_for_a_validated_constraint_and_stamps_a_missing_one(): void
    {
        $original = $this->original();
        $original->down();
        $original->up();
        $kept = $this->validatedAt(self::FK);
        DB::table('integrity_rule_baselines')->where('rule_key', self::CHECK)->update(['validated_at' => null]);

        $this->forward()->up();

        $this->assertSame($kept, $this->validatedAt(self::FK));
        $this->assertNotNull($this->validatedAt(self::CHECK), 'a validated constraint that carried no timestamp gets one');
    }

    public function test_forward_migration_rolls_back_without_touching_data_and_reapplies(): void
    {
        $this->dropFk();
        $m = $this->forward();
        $m->up();
        $after = $this->snapshot();
        $m->down();
        $this->assertSame($after, $this->snapshot());
        $m->up();
        $this->assertSame($after, $this->snapshot());
    }

    public function test_the_real_migrator_rolls_both_migrations_back_and_reapplies_them_to_the_same_state(): void
    {
        $this->dropFk();
        $paths = ['database/migrations/2026_12_10_340000_record_historical_integrity_violations.php', 'database/migrations/2026_12_12_200000_rederive_integrity_rule_baseline_validation_state.php'];
        $this->current()->down();
        $this->current()->up();
        $before = $this->snapshot();

        $this->assertSame(0, Artisan::call('migrate:rollback', ['--path' => $paths, '--force' => true]));
        $this->assertFalse(Schema::hasTable('integrity_rule_baselines'));
        $this->assertSame(0, Artisan::call('migrate', ['--path' => $paths, '--force' => true]));

        $this->assertSame(4, DB::table('integrity_rule_baselines')->count());
        $this->assertNull($this->validatedAt(self::FK));
        $this->assertSame(array_column($before, 'violations_at_introduction'), array_column($this->snapshot(), 'violations_at_introduction'));
    }
}
