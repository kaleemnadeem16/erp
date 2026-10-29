<?php

namespace Tests\Feature\Modules\Tax;

use App\Modules\Tax\Support\Quotation\QuotationTaxGenerationFence;
use App\Modules\Tax\Support\TaxRefusal;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Tests\TestCase;

class QuotationTaxGenerationFenceTest extends TestCase
{
    use DatabaseMigrations;

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

    public function test_fence_refuses_outside_a_transaction(): void
    {
        $this->expectException(TaxRefusal::class);
        app(QuotationTaxGenerationFence::class)->lock();
    }

    public function test_real_tax_writer_waits_for_issue_transaction_and_stale_generation_refuses(): void
    {
        $config = config('database.connections.testing');
        $other = new PDO("pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}", $config['username'], $config['password']);
        $other->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $other->exec("SET lock_timeout = '250ms'");

        DB::table('tax_generations')->insertOrIgnore(['company_id' => 1, 'generation' => 0]);
        $insert = $other->prepare('INSERT INTO tax_components(company_id, code) VALUES (1, ?)');
        DB::beginTransaction();
        try {
            $generation = app(QuotationTaxGenerationFence::class)->lock();
            try {
                $insert->execute(['fence-fixture']);
                $this->fail('A raw Tax writer bypassed the fence.');
            } catch (PDOException $exception) {
                $this->assertSame('55P03', $exception->getCode());
            }
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }

        $insert->execute(['fence-fixture']);
        $this->assertSame($generation + 1, (int) DB::table('tax_generations')->where('company_id', 1)->value('generation'));

        try {
            DB::transaction(fn () => app(QuotationTaxGenerationFence::class)->lock($generation));
            $this->fail('A stale reviewed generation must refuse.');
        } catch (TaxRefusal $exception) {
            $this->assertSame('TAX_GENERATION_STALE', $exception->reasonCode);
        }
        DB::transaction(fn () => $this->assertSame($generation + 1, app(QuotationTaxGenerationFence::class)->lock($generation + 1)));
    }
}
