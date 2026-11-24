<?php

namespace Tests\Feature\Modules\Purchasing;

use App\Models\User;
use App\Modules\Purchasing\Actions\IssuePurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Real PostgreSQL proof (two sessions) that purchase-order record scope is judged on the row as it is under the lock (Q26, ADR 0192).
 *
 * Session B edits a draft's ship-to warehouse and holds the row lock. Session A (a forked process) issues the same order as a person limited to
 * the original warehouse's unit; A waits on the lock. When B commits, the order has left A's reach, so A must read it as absent — not issue it.
 * The control run has B change something that does not move the order, and A must get past the lock stage. Committed fixtures and separately named
 * connections are intentional (see GoodsReceiptConcurrencyTest): RefreshDatabase's ambient transaction is invisible to a forked process.
 */
class PurchaseOrderScopeConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_order_moved_outside_reach_while_issue_waits_is_not_issued(): void
    {
        $outcome = $this->issueWhileOtherSessionEdits('ship_to_warehouse_id = {warehouseB}');

        $this->assertSame(ModelNotFoundException::class, $outcome['class'], 'the order left the issuer\'s reach under the lock: '.json_encode($outcome));
        $this->assertSame('DRAFT', $outcome['status_after'], 'and it was not issued');
    }

    public function test_the_control_run_an_edit_that_does_not_move_the_order_still_reaches_issue(): void
    {
        $outcome = $this->issueWhileOtherSessionEdits("notes = 'edited, not moved'");

        $this->assertNotSame(ModelNotFoundException::class, $outcome['class'], 'an order still in reach is not read as absent: '.json_encode($outcome));
    }

    /** @return array{class: ?string, message: ?string, status_after: string} */
    private function issueWhileOtherSessionEdits(string $sessionBAssignment): array
    {
        $config = config('database.connections.testing');
        $dsn = "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}";
        $pdo = new PDO($dsn, $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $editor = new PDO($dsn, $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $suffix = bin2hex(random_bytes(3));
        [$companyId, $orderId, $actorId, $warehouseB] = $this->createRawFixture($pdo, "poscope_{$suffix}");

        $this->app->instance(CompanyContext::class, new class($companyId) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        app(PermissionRegistrar::class)->setPermissionsTeamId($companyId);

        $resultPath = sys_get_temp_dir().'/po_scope_concurrency_'.getmypid().'.json';
        @unlink($resultPath);

        try {
            // Session B: edit the draft and keep the row lock until told to commit.
            $editor->beginTransaction();
            $editor->exec('update purchase_orders set '.str_replace('{warehouseB}', (string) $warehouseB, $sessionBAssignment)." where id = {$orderId}");

            $childPid = pcntl_fork();
            if ($childPid === -1) {
                $this->fail('Unable to fork a process for the concurrency test.');
            }
            if ($childPid === 0) {
                config(['database.connections.po_scope_child' => config('database.connections.testing')]);
                config(['database.default' => 'po_scope_child']);
                try {
                    $order = PurchaseOrder::query()->findOrFail($orderId); // read before B commits: it still ships to A
                    app(IssuePurchaseOrder::class)($order, User::query()->findOrFail($actorId));
                    file_put_contents($resultPath, json_encode(['class' => null, 'message' => 'issued']));
                } catch (\Throwable $e) {
                    file_put_contents($resultPath, json_encode(['class' => $e::class, 'message' => $e->getMessage()]));
                }
                posix_kill(getmypid(), SIGKILL);
            }

            // Wait until A is genuinely blocked on the row lock, then let B commit.
            $blocked = false;
            for ($i = 0; $i < 100 && ! $blocked; $i++) {
                usleep(100_000);
                $blocked = (int) $pdo->query("select count(*) from pg_stat_activity where wait_event_type = 'Lock' and query ilike '%from \"purchase_orders\"%for update%'")->fetchColumn() > 0;
            }
            $this->assertTrue($blocked, 'the issuing session never waited on the order row lock; the test did not exercise the race');
            $editor->commit();

            pcntl_waitpid($childPid, $status);
            $outcome = json_decode((string) file_get_contents($resultPath), true);
            $this->assertIsArray($outcome, 'the forked issuing session did not report an outcome');
            $outcome['status_after'] = (string) $pdo->query("select status from purchase_orders where id = {$orderId}")->fetchColumn();

            return $outcome;
        } finally {
            if ($editor->inTransaction()) {
                $editor->rollBack();
            }
            @unlink($resultPath);
            $this->cleanUpRawFixture($pdo, $companyId);
        }
    }

    /** @return array{int, int, int, int} company, draft order, actor (limited to unit A on issue), warehouse B */
    private function createRawFixture(PDO $pdo, string $prefix): array
    {
        $companyId = (int) $pdo->query("insert into companies (name, created_at, updated_at) values ('{$prefix}', now(), now()) returning id")->fetchColumn();
        $pdo->exec("insert into currencies (code, name, symbol, decimal_places, created_at, updated_at) select 'PSX', 'Scope Concurrency Currency', 'P', 2, now(), now() where not exists (select 1 from currencies where code = 'PSX')");
        $currencyId = (int) $pdo->query("select id from currencies where code = 'PSX'")->fetchColumn();
        $uomId = (int) $pdo->query("insert into uoms (company_id, code, name, dimension, factor_to_base, is_base, created_at, updated_at) values ({$companyId}, '{$prefix}_ea', '{$prefix} Each', 'COUNT', 1, false, now(), now()) returning id")->fetchColumn();
        $payableId = (int) $pdo->query("insert into chart_of_accounts (company_id, code, name, type, is_postable, status, created_at, updated_at) values ({$companyId}, '{$prefix}-pay', '{$prefix} Payable', 'LIABILITY', true, 'ACTIVE', now(), now()) returning id")->fetchColumn();
        $categoryId = (int) $pdo->query("insert into categories (company_id, code, name, created_at, updated_at) values ({$companyId}, '{$prefix}_cat', '{$prefix} Category', now(), now()) returning id")->fetchColumn();
        $itemId = (int) $pdo->query("insert into items (company_id, code, name, type, stock_uom_id, is_stocked, valuation_method, tracking, category_id, status, created_at, updated_at) values ({$companyId}, '{$prefix}_item', '{$prefix} Item', 'RAW', {$uomId}, true, 'FIFO', 'NONE', {$categoryId}, 'ACTIVE', now(), now()) returning id")->fetchColumn();
        $warehouseA = (int) $pdo->query("insert into warehouses (company_id, code, name, is_default, active, created_at, updated_at) values ({$companyId}, '{$prefix}_a', '{$prefix} A', false, true, now(), now()) returning id")->fetchColumn();
        $warehouseB = (int) $pdo->query("insert into warehouses (company_id, code, name, is_default, active, created_at, updated_at) values ({$companyId}, '{$prefix}_b', '{$prefix} B', false, true, now(), now()) returning id")->fetchColumn();
        $partyId = (int) $pdo->query("insert into parties (company_id, code, name, status, created_at, updated_at) values ({$companyId}, '{$prefix}_party', '{$prefix} Party', 'ACTIVE', now(), now()) returning id")->fetchColumn();
        $supplierId = (int) $pdo->query("insert into supplier_accounts (company_id, party_id, code, payment_terms_days, payable_account_id, status, created_at, updated_at) values ({$companyId}, {$partyId}, '{$prefix}_sup', 30, {$payableId}, 'ACTIVE', now(), now()) returning id")->fetchColumn();
        $actorId = (int) $pdo->query("insert into users (company_id, name, email, password, created_at, updated_at) values ({$companyId}, '{$prefix} User', '{$prefix}@example.com', 'x', now(), now()) returning id")->fetchColumn();
        $permissionId = (int) $pdo->query("insert into permissions (name, guard_name, created_at, updated_at) values ('purchasing.purchase-order.issue', 'web', now(), now()) on conflict (name, guard_name) do update set updated_at = excluded.updated_at returning id")->fetchColumn();
        $pdo->exec("insert into model_has_permissions (permission_id, model_type, model_id, team_id) values ({$permissionId}, ".$pdo->quote(User::class).", {$actorId}, {$companyId}) on conflict do nothing");
        $unitId = (int) $pdo->query("insert into organisation_units (company_id, code, name, status, created_at, updated_at) values ({$companyId}, '{$prefix}_u', '{$prefix} Unit', 'ACTIVE', now(), now()) returning id")->fetchColumn();
        $pdo->exec("insert into organisation_unit_resources (company_id, unit_id, resource_type, resource_id, created_at, updated_at) values ({$companyId}, {$unitId}, 'warehouse', {$warehouseA}, now(), now())");
        $pdo->exec("insert into organisation_unit_members (company_id, unit_id, user_id, created_at, updated_at) values ({$companyId}, {$unitId}, {$actorId}, now(), now())");
        $pdo->exec("insert into access_scope_limits (company_id, user_id, permission, scope, created_at, updated_at) values ({$companyId}, {$actorId}, 'purchasing.purchase-order.issue', 'MY_UNITS', now(), now())");
        $orderId = (int) $pdo->query("insert into purchase_orders (company_id, supplier_account_id, currency_id, fx_rate, fx_rate_date, fx_rate_type, fx_rate_source, order_date, ship_to_warehouse_id, created_at, updated_at) values ({$companyId}, {$supplierId}, {$currencyId}, 1, current_date, 'SPOT', 'manual', current_date, {$warehouseA}, now(), now()) returning id")->fetchColumn();
        $pdo->exec("insert into purchase_order_lines (company_id, purchase_order_id, line_number, item_id, description, ordered_qty, purchase_uom_id, ordered_stock_qty, stock_uom_id, uom_factor, unit_price, line_amount, base_unit_price, base_line_amount, qa_required, over_receipt_mode, created_at, updated_at) values ({$companyId}, {$orderId}, 1, {$itemId}, '{$prefix} line', 1, {$uomId}, 1, {$uomId}, 1, 10, 10, 10, 10, false, 'BLOCK', now(), now())");

        return [$companyId, $orderId, $actorId, $warehouseB];
    }

    private function cleanUpRawFixture(PDO $pdo, int $companyId): void
    {
        foreach (['purchase_order_lines' => 'purchase_order_lines_guard', 'purchase_orders' => 'purchase_orders_guard'] as $table => $trigger) {
            $pdo->exec("alter table {$table} disable trigger {$trigger}");
        }
        try {
            $pdo->exec("delete from purchase_order_lines where company_id = {$companyId}");
            $pdo->exec("delete from purchase_orders where company_id = {$companyId}");
        } finally {
            foreach (['purchase_order_lines' => 'purchase_order_lines_guard', 'purchase_orders' => 'purchase_orders_guard'] as $table => $trigger) {
                $pdo->exec("alter table {$table} enable trigger {$trigger}");
            }
        }
        foreach ($pdo->query("select sequence_name from numbering_counters where company_id = {$companyId} and sequence_name is not null")->fetchAll(PDO::FETCH_COLUMN) as $sequenceName) {
            $pdo->exec("drop sequence if exists {$sequenceName}");
        }
        foreach (['numbering_allocations', 'numbering_counter_adjustments', 'numbering_counters', 'numbering_series_rules', 'numbering_series_versions', 'numbering_series'] as $table) {
            $pdo->exec("delete from {$table} where company_id = {$companyId}");
        }
        $pdo->exec("delete from model_has_permissions where team_id = {$companyId}");
        foreach (['access_scope_limits', 'organisation_unit_members', 'organisation_unit_resources', 'organisation_units', 'sequences', 'activity_log', 'users', 'supplier_accounts', 'parties'] as $table) {
            $pdo->exec("delete from {$table} where company_id = {$companyId}");
        }
        $pdo->exec("delete from locations where warehouse_id in (select id from warehouses where company_id = {$companyId})");
        foreach (['warehouses', 'items', 'categories', 'chart_of_accounts', 'uoms'] as $table) {
            $pdo->exec("delete from {$table} where company_id = {$companyId}");
        }
        $pdo->exec("delete from companies where id = {$companyId}");
        $pdo->exec("delete from currencies where code = 'PSX'");
    }
}
