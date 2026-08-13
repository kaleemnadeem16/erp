<?php

namespace Tests\Feature\Support;

use App\Contracts\NumberingScheme;
use App\Models\Sequence;
use App\Support\CompanyContext;
use App\Support\DefaultNumberingScheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Tests\TestCase;

class DefaultNumberingSchemeTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_is_bound_as_the_default_numbering_scheme(): void
    {
        $this->assertInstanceOf(DefaultNumberingScheme::class, app(NumberingScheme::class));
    }

    public function test_the_first_call_creates_the_row_and_numbers_are_gapless(): void
    {
        $scheme = new DefaultNumberingScheme;

        $this->assertSame('0001', $scheme->next('invoice'));
        $this->assertSame('0002', $scheme->next('invoice'));
        $this->assertSame('0003', $scheme->next('invoice'));
    }

    public function test_prefix_and_padding_are_configuration_not_code(): void
    {
        Sequence::create(['doc_type' => 'quotation', 'prefix' => 'QUO-', 'padding' => 5]);

        $this->assertSame('QUO-00001', (new DefaultNumberingScheme)->next('quotation'));
    }

    public function test_different_doc_types_have_independent_sequences(): void
    {
        $scheme = new DefaultNumberingScheme;

        $this->assertSame('0001', $scheme->next('invoice'));
        $this->assertSame('0001', $scheme->next('quotation'));
        $this->assertSame('0002', $scheme->next('invoice'));
    }

    public function test_sequences_are_scoped_per_company(): void
    {
        $scheme = new DefaultNumberingScheme;
        $this->assertSame('0001', $scheme->next('invoice'));

        DB::table('companies')->insert(['id' => 2, 'name' => 'Company 2', 'created_at' => now(), 'updated_at' => now()]);

        $this->app->instance(CompanyContext::class, new class extends CompanyContext
        {
            public function id(): int
            {
                return 2;
            }
        });

        $this->assertSame('0001', $scheme->next('invoice'));
        $this->assertSame(2, Sequence::withoutGlobalScopes()->where('company_id', 2)->first()->company_id);
    }

    /**
     * Proves the mechanism behind invariant 8 is real, not assumed: a transaction
     * holding the row lock genuinely blocks a second transaction from reading the
     * same row, rather than both racing to read the same next_number. A real
     * two-process concurrency test would show the same outcome (different, gapless
     * numbers) but can't prove *why* — this isolates the actual locking behaviour.
     *
     * Uses two raw PDO connections instead of the app's own (RefreshDatabase wraps
     * each test in one big uncommitted transaction on the app's connection, which a
     * genuinely separate session — like these two — could never see, so the row has
     * to be inserted through one of them directly rather than via DefaultNumberingScheme).
     */
    public function test_a_second_transaction_is_blocked_by_the_row_lock_until_the_first_releases_it(): void
    {
        $config = config('database.connections.testing');
        $dsn = "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}";

        $pdoA = new PDO($dsn, $config['username'], $config['password']);
        $pdoB = new PDO($dsn, $config['username'], $config['password']);

        $pdoA->exec(
            'insert into sequences (company_id, doc_type, next_number, padding, created_at, updated_at) '.
            "values (1, 'lock_test_probe', 1, 4, now(), now())"
        );

        try {
            $pdoA->beginTransaction();
            $pdoA->query("select next_number from sequences where doc_type = 'lock_test_probe' for update");

            $pdoB->beginTransaction();
            $pdoB->exec("set local lock_timeout = '200ms'");

            $this->expectException(PDOException::class);
            $pdoB->query("select next_number from sequences where doc_type = 'lock_test_probe' for update");
        } finally {
            $pdoA->rollBack();
            $pdoB->rollBack();
            $pdoA->exec("delete from sequences where doc_type = 'lock_test_probe'");
        }
    }
}
