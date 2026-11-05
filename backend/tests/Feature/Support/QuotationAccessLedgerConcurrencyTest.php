<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Modules\Sales\Actions\ReassignQuotationOwner;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Tests\Feature\Modules\Sales\Concerns\BuildsQuotationOwnership;
use Tests\Support\BuildsQuotationShareStorage;
use Tests\TestCase;

class QuotationAccessLedgerConcurrencyTest extends TestCase
{
    use BuildsQuotationOwnership, BuildsQuotationShareStorage, DatabaseMigrations;

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

    public function test_a_competing_share_cannot_cross_an_ownership_change_or_reuse_its_old_pins(): void
    {
        $this->setUpOwnership();
        $this->activateOwnershipDuties();
        $family = $this->nativeFamily();
        // Capture a real valid storage payload, then roll back this fixture-only probe.
        DB::beginTransaction();
        $probe = $this->rawGrant($family, $this->preparer, $this->colleague);
        $payload = (array) DB::table('quotation_access_grants')->where('id', $probe)->first();
        DB::rollBack();
        unset($payload['id'], $payload['recorded_at']);
        $config = config('database.connections.testing');
        $raw = new PDO("pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}", $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $raw->exec("SET statement_timeout = '5s'");
        $insert = $raw->prepare('INSERT INTO quotation_access_grants ('.implode(',', array_keys($payload)).') VALUES ('.implode(',', array_fill(0, count($payload), '?')).')');

        DB::beginTransaction();
        try {
            app(ReassignQuotationOwner::class)($this->assigner, $family->id, $this->colleague->id, 1, 0, $this->key(), 'Concurrent owner change fixture');
            try {
                $insert->execute(array_values($payload));
                $this->fail('The competing share cannot cross the held ownership transaction.');
            } catch (PDOException $failure) {
                $this->assertSame('55P03', $failure->errorInfo[0], 'The bounded security fence ends the wait before the outer statement deadline.');
            }
            $this->assertSame(0, DB::table('quotation_access_grants')->count());
            $this->assertSame(0, DB::table('quotation_access_events')->count());
            DB::commit();
        } catch (\Throwable $failure) {
            DB::rollBack();
            throw $failure;
        }
        try {
            $insert->execute(array_values($payload));
            $this->fail('The ownership commit permanently invalidates the old share pins.');
        } catch (PDOException $failure) {
            $this->assertSame('23514', $failure->errorInfo[0]);
            $this->assertStringContainsString('pin current active same-company authority', $failure->getMessage());
        }
        $this->assertSame(0, DB::table('quotation_access_grants')->count());
        $grant = $this->rawGrant($family, $this->preparer, $this->colleague);
        $this->assertSame(1, DB::table('quotation_access_events')->where('grant_id', $grant)->count());
        $this->assertSame([2, 1], [$family->fresh()->ownership_generation, $family->fresh()->access_generation]);
        app(ReassignQuotationOwner::class)($this->assigner, $family->id, $this->preparer->id, 2, 1, $this->key(), 'Owner change after competing share fixture');
        $this->assertSame([3, 1], [$family->fresh()->ownership_generation, $family->fresh()->access_generation]);
        $this->assertSame(1, (int) DB::table('quotation_ownership_events')->where('family_id', $family->id)->orderByDesc('generation')->value('access_generation'));
        $this->assertSame('0', $raw->query('SHOW lock_timeout')->fetchColumn());
    }
}
