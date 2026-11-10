<?php

declare(strict_types=1);

namespace Tests\Feature\Security\Concerns;

use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;
use PDO;
use Spatie\Permission\PermissionRegistrar;

/**
 * Real two-session races on PostgreSQL for the security-verification matrix (issue #91).
 *
 * The ordering is made deterministic without touching production code: the test (the "holder") opens its own transaction
 * and locks the row the Action's transaction locks first. A forked process then runs the real Action on its own connection:
 * everything the Action does BEFORE its transaction (the actor's permission check) has already happened by the time it
 * blocks on that row lock. Once PostgreSQL reports the Action waiting, the holder commits a security change (revocation,
 * suspension, scope limit, expiry, ...) and releases the lock. The Action then continues and commits or refuses.
 *
 * The fixture is committed (the forked process cannot see RefreshDatabase's ambient transaction), so every test must call
 * purgeRaceCompany() in a finally block. Cleanup removes only rows of the company the test created (plus the permission and
 * currency rows it created itself); trigger/foreign-key enforcement is switched off for that cleanup session only.
 */
trait TwoSessionRace
{
    /** @var list<string> permission names this test inserted into `permissions` (removed again by the purge) */
    private array $createdPermissions = [];

    private bool $createdCurrency = false;

    private function raceSession(): PDO
    {
        $config = config('database.connections.testing');
        $pdo = new PDO("pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}", $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("SET lock_timeout = '20s'");

        return $pdo;
    }

    /**
     * A committed company with the reference data every scenario needs.
     *
     * @return array<string, int>
     */
    private function raceCompany(PDO $pdo, string $prefix): array
    {
        $q = fn (string $sql): int => (int) $pdo->query($sql)->fetchColumn();
        $company = $q("insert into companies (name, created_at, updated_at) values ('{$prefix}', now(), now()) returning id");
        $this->createdCurrency = $q("select count(*) from currencies where code = 'GCX'") === 0;
        $pdo->exec("insert into currencies (code, name, symbol, decimal_places, created_at, updated_at) select 'GCX', 'Goods Concurrency Currency', 'G', 2, now(), now() where not exists (select 1 from currencies where code = 'GCX')");
        $currency = $q("select id from currencies where code = 'GCX'");
        $pdo->exec("update companies set base_currency_id = {$currency} where id = {$company}");
        $uom = $q("insert into uoms (company_id, code, name, dimension, factor_to_base, is_base, created_at, updated_at) values ({$company}, '{$prefix}_ea', '{$prefix} Each', 'COUNT', 1, false, now(), now()) returning id");
        $payable = $q("insert into chart_of_accounts (company_id, code, name, type, is_postable, status, created_at, updated_at) values ({$company}, '{$prefix}-pay', '{$prefix} Payable', 'LIABILITY', true, 'ACTIVE', now(), now()) returning id");
        $inventory = $q("insert into chart_of_accounts (company_id, code, name, type, is_postable, status, created_at, updated_at) values ({$company}, '{$prefix}-inv', '{$prefix} Inventory', 'ASSET', true, 'ACTIVE', now(), now()) returning id");
        $category = $q("insert into categories (company_id, code, name, created_at, updated_at) values ({$company}, '{$prefix}_cat', '{$prefix} Category', now(), now()) returning id");
        $item = $q("insert into items (company_id, code, name, type, stock_uom_id, is_stocked, valuation_method, tracking, category_id, inventory_account_id, status, created_at, updated_at) values ({$company}, '{$prefix}_item', '{$prefix} Item', 'RAW', {$uom}, true, 'FIFO', 'NONE', {$category}, {$inventory}, 'ACTIVE', now(), now()) returning id");
        $warehouse = $q("insert into warehouses (company_id, code, name, is_default, active, created_at, updated_at) values ({$company}, '{$prefix}_wh', '{$prefix} Warehouse', false, true, now(), now()) returning id");
        $location = $q("insert into locations (warehouse_id, code, name, active, created_at, updated_at) values ({$warehouse}, '{$prefix}_loc', '{$prefix} Location', true, now(), now()) returning id");
        $party = $q("insert into parties (company_id, code, name, status, created_at, updated_at) values ({$company}, '{$prefix}_party', '{$prefix} Party', 'ACTIVE', now(), now()) returning id");
        $supplier = $q("insert into supplier_accounts (company_id, party_id, code, payment_terms_days, payable_account_id, status, created_at, updated_at) values ({$company}, {$party}, '{$prefix}_sup', 30, {$payable}, 'ACTIVE', now(), now()) returning id");

        return compact('company', 'currency', 'uom', 'item', 'warehouse', 'location', 'supplier');
    }

    private function raceUser(PDO $pdo, int $company, string $prefix, string $label): int
    {
        return (int) $pdo->query("insert into users (company_id, name, email, password, status, created_at, updated_at) values ({$company}, '{$prefix} {$label}', '{$prefix}.{$label}@example.com', 'x', 'ACTIVE', now(), now()) returning id")->fetchColumn();
    }

    private function raceGrant(PDO $pdo, int $company, int $userId, string $permission): void
    {
        $id = $pdo->query("select id from permissions where name = '{$permission}' and guard_name = 'web'")->fetchColumn();
        if ($id === false) {
            $id = $pdo->query("insert into permissions (name, guard_name, created_at, updated_at) values ('{$permission}', 'web', now(), now()) returning id")->fetchColumn();
            $this->createdPermissions[] = $permission;
        }
        $pdo->exec('insert into model_has_permissions (permission_id, model_type, model_id, team_id) values ('.(int) $id.", '".User::class."', {$userId}, {$company}) on conflict do nothing");
    }

    private function raceRevoke(PDO $pdo, int $userId, string $permission): void
    {
        $pdo->exec("delete from model_has_permissions where model_id = {$userId} and permission_id = (select id from permissions where name = '{$permission}' and guard_name = 'web')");
    }

    private function raceBindCompany(int $company): void
    {
        $this->app->instance(CompanyContext::class, new class($company) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(PermissionRegistrar::class)->setPermissionsTeamId($company);
    }

    /** Run committed setup (Eloquent Actions) on a connection that has no ambient transaction. */
    private function raceCommitted(callable $setup): mixed
    {
        $original = config('database.default');
        config(['database.connections.race_setup' => config('database.connections.testing'), 'database.default' => 'race_setup']);
        try {
            return $setup();
        } finally {
            config(['database.default' => $original]);
            DB::purge('race_setup');
        }
    }

    /**
     * Hold `$gateSql` (a locking SELECT) in the test's own transaction, run `$action` in a forked process, wait until
     * PostgreSQL shows that process blocked on a lock, then let `$mutate` change the world and commit everything.
     *
     * @param  string|list<string>  $gateSql  one or several locking statements, run in order
     * @param  callable(): mixed  $action  runs in the child on its own connection; its return value is discarded
     * @param  callable(PDO): void  $mutate  runs in the holder's transaction while the child is blocked
     * @return array{success: bool, message: string|null, class: string|null}
     */
    private function raceAction(PDO $holder, string|array $gateSql, callable $action, callable $mutate, float $settleSeconds = 0.0): array
    {
        $result = sys_get_temp_dir().'/sec_race_'.getmypid().'_'.bin2hex(random_bytes(3)).'.json';
        @unlink($result);
        $holder->beginTransaction();
        foreach ((array) $gateSql as $statement) {
            $holder->query($statement)->fetchAll();
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            $holder->rollBack();
            $this->fail('Unable to fork a process for the race test.');
        }
        if ($pid === 0) {
            config(['database.connections.race_child' => config('database.connections.testing'), 'database.default' => 'race_child']);
            try {
                $action();
                file_put_contents($result, json_encode(['success' => true, 'message' => null, 'class' => null]));
            } catch (\Throwable $e) {
                file_put_contents($result, json_encode(['success' => false, 'message' => $e->getMessage(), 'class' => $e::class]));
            }
            posix_kill(getmypid(), SIGKILL);
        }

        // pg_stat_activity is a per-transaction snapshot, so the polling must use its own autocommit session, never the holder's.
        $monitor = $this->raceSession();
        try {
            $blocked = false;
            for ($i = 0; $i < 400 && ! $blocked; $i++) {
                $blocked = (int) $monitor->query("select count(*) from pg_stat_activity where datname = current_database() and wait_event_type = 'Lock' and pid <> pg_backend_pid()")->fetchColumn() > 0;
                $blocked || usleep(25_000);
            }
            if (! $blocked) {
                $holder->rollBack();
                pcntl_waitpid($pid, $status);
                $this->fail('the Action never reached its first row lock; the race could not be staged. Child said: '.(string) @file_get_contents($result));
            }
            $mutate($holder);
            if ($settleSeconds > 0) {
                usleep((int) ($settleSeconds * 1_000_000));
            }
            $holder->commit();
        } finally {
            if ($holder->inTransaction()) {
                $holder->rollBack();
            }
            pcntl_waitpid($pid, $status);
        }
        $outcome = json_decode((string) @file_get_contents($result), true);
        @unlink($result);
        $this->assertIsArray($outcome, 'the forked process did not report an outcome');

        return $outcome;
    }

    /** Remove one test company and everything under it; only this company's rows (and rows this test created) are touched. */
    private function purgeRaceCompany(PDO $pdo, int $company): void
    {
        $pdo->exec('SET session_replication_role = replica');
        try {
            foreach (['numbering_counters' => 'sequence_name'] as $table => $column) {
                foreach ($pdo->query("select {$column} from {$table} where company_id = {$company} and {$column} is not null")->fetchAll(PDO::FETCH_COLUMN) as $sequence) {
                    $pdo->exec("drop sequence if exists {$sequence}");
                }
            }
            $tables = $pdo->query("select table_name from information_schema.columns where table_schema = 'public' and column_name = 'company_id' and table_name <> 'companies' order by table_name")->fetchAll(PDO::FETCH_COLUMN);
            // Children that carry no company_id of their own, found through their foreign keys to company-scoped parents.
            $children = $pdo->query(<<<'SQL'
                select c.conrelid::regclass::text as child, a.attname as child_col, c.confrelid::regclass::text as parent
                from pg_constraint c join pg_attribute a on a.attrelid = c.conrelid and a.attnum = c.conkey[1]
                where c.contype = 'f' and array_length(c.conkey, 1) = 1
                SQL)->fetchAll(PDO::FETCH_ASSOC);
            $scoped = array_flip($tables);
            $pdo->exec("delete from model_has_permissions where team_id = {$company}");
            $pdo->exec("delete from model_has_roles where team_id = {$company}");
            for ($pass = 0; $pass < 4; $pass++) {
                foreach ($children as $fk) {
                    $child = $fk['child'];
                    if (isset($scoped[$child]) || str_contains($child, '.') || in_array($child, ['companies', 'users'], true)) {
                        continue;
                    }
                    if (isset($scoped[$fk['parent']])) {
                        try {
                            $pdo->exec("delete from {$child} where {$fk['child_col']} in (select id from {$fk['parent']} where company_id = {$company})");
                        } catch (\PDOException) {
                            // a parent without an `id` key: nothing to sweep through this foreign key
                        }
                    }
                }
            }
            foreach ($tables as $table) {
                $pdo->exec("delete from {$table} where company_id = {$company}");
            }
            // Second sweep for children whose company-scoped parent is a table with a different key (e.g. locations -> warehouses).
            $pdo->exec('delete from locations where warehouse_id not in (select id from warehouses)');
            $pdo->exec("delete from companies where id = {$company}");
            foreach ($this->createdPermissions as $permission) {
                $pdo->exec("delete from permissions where name = '{$permission}' and guard_name = 'web'");
            }
            if ($this->createdCurrency) {
                $pdo->exec("delete from currencies where code = 'GCX'");
            }
        } finally {
            $pdo->exec('SET session_replication_role = DEFAULT');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
