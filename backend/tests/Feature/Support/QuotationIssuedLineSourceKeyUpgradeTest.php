<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Documents the already-migrated-database question for the issued-line source key (independent review B, R6/O2).
 *
 * The composite source/parent-identity FK was made by editing 2026_10_29_120000 in ef81884a, five hours after the first version (588ceb11, kept as a fixture) and BEFORE any
 * pushed ref contained 588ceb11: every remote branch and every origin/develop state that holds 588ceb11 also holds ef81884a. Only a database migrated locally in that window
 * could carry the old two-column FK, so no forward migration is shipped. If such a database is ever found, it must be rebuilt (migrate:fresh) or repaired by hand.
 */
class QuotationIssuedLineSourceKeyUpgradeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_only_earlier_version_bound_the_source_line_by_company_and_id_alone(): void
    {
        $old = (string) file_get_contents(base_path('tests/Fixtures/migrations/original_588ceb11_create_quotation_issue_foundation.php'));

        $this->assertStringContainsString("foreign(['company_id', 'source_line_id'], 'quotation_issued_lines_source_fk')", $old);
        $this->assertStringNotContainsString('quotation_draft_lines_parent_key_unique', $old);
    }

    public function test_every_database_built_from_the_pushed_migrations_binds_the_source_line_to_its_revision_and_scope(): void
    {
        $fk = DB::selectOne("SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'quotation_issued_lines_source_fk' AND conrelid = 'quotation_issued_lines'::regclass");
        $this->assertNotNull($fk);
        $this->assertSame('FOREIGN KEY (company_id, revision_id, source_line_id, logical_scope_id) REFERENCES quotation_draft_lines(company_id, revision_id, id, logical_scope_id)', $fk->def);

        $this->assertSame(1, DB::table('pg_constraint')->where('conname', 'quotation_draft_lines_parent_key_unique')->where('contype', 'u')->count());
        $issueFk = DB::selectOne("SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'quotation_issued_lines_issue_fk'");
        $this->assertStringContainsString('(company_id, issue_id, revision_id, family_id)', $issueFk->def);
    }
}
