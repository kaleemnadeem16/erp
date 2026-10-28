<?php

namespace Tests\Feature\Modules\Tax;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Tests\Feature\Modules\Tax\Concerns\BuildsQuotationTax;
use Tests\TestCase;

/** Real commits and independent sessions prove the database guard, not just a rolled-back fixture. */
class TaxQuotationDeclarationCommitTest extends TestCase
{
    use BuildsQuotationTax, DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();
        $this->beforeApplicationDestroyed(function (): void {
            $this->artisan('migrate:fresh', $this->migrateFreshUsing());
            RefreshDatabaseState::$migrated = false;
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpQuotationTax();
    }

    public function test_activation_serializes_raw_child_writer_and_committed_activation_freezes_coverage(): void
    {
        $id = $this->declaration([$this->coverage()]);
        $row = (array) DB::table('tax_quotation_declaration_rules')->where('declaration_version_id', $id)->first();
        $config = config('database.connections.testing');
        $other = new PDO("pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}", $config['username'], $config['password']);
        $other->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $other->exec("SET lock_timeout = '250ms'");
        $statement = $other->prepare('INSERT INTO tax_quotation_declaration_rules(company_id,declaration_version_id,nature,classification,group_version_id,component_id,rule_version_id,disposition,requires_party_profile,grounds,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $values = [$row['company_id'], $id, $row['nature'], 'contender', $row['group_version_id'], $row['component_id'], $row['rule_version_id'], $row['disposition'], 'false', 'fixture', $this->actor->id];
        DB::beginTransaction();
        try {
            DB::table('tax_quotation_declaration_activations')->insert(['company_id' => 1, 'declaration_version_id' => $id, 'activated_by' => $this->actor->id, 'reason' => 'fixture']);
            try {
                $statement->execute($values);
                $this->fail('The competing insert bypassed the generation lock.');
            } catch (PDOException $e) {
                $this->assertSame('55P03', $e->getCode());
            }
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        try {
            $statement->execute($values);
            $this->fail('The committed activation must freeze the children.');
        } catch (PDOException $e) {
            $this->assertSame('23514', $e->getCode());
        }
        $this->assertSame(1, DB::table('tax_quotation_declaration_rules')->where('declaration_version_id', $id)->count());
        $this->assertSame(1, DB::table('tax_quotation_declaration_activations')->where('declaration_version_id', $id)->count());
    }
}
