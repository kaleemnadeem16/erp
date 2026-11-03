<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Modules\Sales\Actions\ListQuotations;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Feature\Modules\Sales\Concerns\BuildsQuotationOwnership;
use Tests\TestCase;

class QuotationListSnapshotConcurrencyTest extends TestCase
{
    use BuildsQuotationOwnership, DatabaseMigrations;

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
        $this->setUpOwnership();
        $this->activateOwnershipDuties();
    }

    public function test_an_independent_header_change_cannot_split_count_from_page(): void
    {
        $family = $this->nativeFamily();
        $revision = $family->revisions()->firstOrFail()->id;
        $config = config('database.connections.testing');
        $other = new PDO("pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}", $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $other->exec("SET lock_timeout = '250ms'");
        $other->exec("SET statement_timeout = '2s'");
        $changed = false;
        DB::listen(function (QueryExecuted $query) use (&$changed, $other, $revision): void {
            if (! $changed && str_contains($query->sql, 'quotation_revisions')) {
                $changed = true;
                // INSERT advances the fenced family generation; raw DRAFT header updates
                // do not. Change filter membership through a separate committed connection.
                $statement = $other->prepare('UPDATE quotation_revisions SET recipient_party_id = NULL WHERE id = ?');
                $statement->execute([$revision]);
            }
        });
        $input = ['sort' => 'id', 'per_page' => 100, 'filter' => ['recipient_party_id' => $this->prospect->id]];
        $first = app(ListQuotations::class)($this->preparer, $input);
        $this->assertTrue($changed, 'the independent header update must actually execute');
        $this->assertSame(1, $first->total());
        $this->assertCount(1, $first->items(), 'the old two-query paginator counted one row then returned none');
        $next = app(ListQuotations::class)($this->preparer, $input);
        $this->assertSame(0, $next->total());
        $this->assertCount(0, $next->items(), 'a later decision sees the committed filter-membership change');
    }
}
