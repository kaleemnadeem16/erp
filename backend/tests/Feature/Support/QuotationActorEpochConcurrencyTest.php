<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\User;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as Duty;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class QuotationActorEpochConcurrencyTest extends TestCase
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

    public function test_a_raw_assignment_is_bounded_on_an_actor_row_and_leaves_no_partial_grant(): void
    {
        $actor = User::factory()->create();
        $permission = Permission::findOrCreate(Duty::QUOTATION_VIEW, 'web');
        $config = config('database.connections.testing');
        $raw = new PDO("pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}", $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $raw->exec("SET lock_timeout = '0'");
        $raw->exec("SET statement_timeout = '5s'");
        $insert = $raw->prepare('INSERT INTO model_has_permissions(permission_id,model_type,model_id,team_id) VALUES (?,?,?,?)');
        $values = [$permission->id, $actor->getMorphClass(), $actor->id, 1];
        $generation = (int) DB::table('company_access_generations')->where('company_id', 1)->value('generation');
        $profile = DB::getPdo();
        $profile->beginTransaction();
        try {
            $profile->exec('UPDATE users SET name = \'Held profile fixture\' WHERE id = '.(int) $actor->id);
            $this->assertSame($generation, (int) $profile->query('SELECT generation FROM company_access_generations WHERE company_id=1')->fetchColumn(), 'A profile change acquires no company fence.');
            try {
                $insert->execute($values);
                $this->fail('A raw security write cannot wait unboundedly on an actor row.');
            } catch (PDOException $failure) {
                $this->assertSame('55P03', $failure->errorInfo[0], 'The trigger lock bound, not the outer statement deadline, ends this wait.');
            }
        } finally {
            $profile->rollBack();
        }
        $this->assertSame(0, DB::table('model_has_permissions')->where('model_id', $actor->id)->count());
        $this->assertSame(0, (int) DB::table('users')->where('id', $actor->id)->value('authorization_generation'));
        $this->assertSame($generation, (int) DB::table('company_access_generations')->where('company_id', 1)->value('generation'), 'The failed assignment rolls back its company epoch too.');
        $this->assertSame('0', $raw->query('SHOW lock_timeout')->fetchColumn(), 'Autocommit restores the caller setting.');

        $raw->beginTransaction();
        try {
            $raw->exec("SET LOCAL lock_timeout = '5ms'");
            $insert->execute($values);
            $this->assertSame('5ms', $raw->query('SHOW lock_timeout')->fetchColumn(), 'A stricter caller bound is preserved.');
            $raw->commit();
        } catch (\Throwable $failure) {
            if ($raw->inTransaction()) {
                $raw->rollBack();
            }
            throw $failure;
        }
        $this->assertSame(1, (int) DB::table('users')->where('id', $actor->id)->value('authorization_generation'));
        $this->assertSame(1, DB::table('model_has_permissions')->where('model_id', $actor->id)->count());
        $this->assertFalse(app(SalesDutyManifest::class)->isActive(Duty::QUOTATION_VIEW), 'Storage fixtures never activate the staged duty.');
    }
}
