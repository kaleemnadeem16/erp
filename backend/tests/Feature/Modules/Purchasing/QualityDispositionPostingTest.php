<?php

namespace Tests\Feature\Modules\Purchasing;

use App\Exceptions\DomainInvariantException;
use App\Models\Currency;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Support\AccountType;
use App\Modules\Inventory\Actions\CreateWarehouse;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\ItemTracking;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Models\PartySite;
use App\Modules\Parties\Models\SupplierAccount;
use App\Modules\Parties\Models\SupplierSite;
use App\Modules\Purchasing\Actions\IssuePurchaseOrder;
use App\Modules\Purchasing\Actions\PostQualityDisposition;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Models\QualityDisposition;
use App\Support\CompanyContext;
use App\Support\UomDimension;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PDO;
use PDOException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\PurchasingActor;

class QualityDispositionPostingTest extends PurchaseOrderFoundationTest
{
    public function test_disposition_list_and_detail_require_receipt_read_access(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(qaRequired: true);
        $lineId = $this->postedLine($order->lines()->sole()->id, $item->id, $uom->id, $this->locationId());
        $dispositionId = $this->postDisposition($lineId, [
            'disposition' => 'PASS', 'qty' => '2.0000', 'reason' => 'inspected ok',
        ])->assertCreated()->json('data.id');

        $viewer = User::factory()->create();
        $this->actingAsUnprivileged($viewer)
            ->getJson("/api/v1/goods-receipt-lines/{$lineId}/quality-dispositions")
            ->assertForbidden();
        $this->getJson("/api/v1/quality-dispositions/{$dispositionId}")->assertForbidden();

        PurchasingActor::grant($viewer, ['purchasing.goods-receipt.view']);
        $this->getJson("/api/v1/goods-receipt-lines/{$lineId}/quality-dispositions")
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/quality-dispositions/{$dispositionId}")->assertOk();
        $this->postJson("/api/v1/goods-receipt-lines/{$lineId}/quality-dispositions", [
            'disposition' => 'PASS', 'qty' => '0.1000', 'reason' => 'not allowed',
        ])
            ->assertForbidden();
    }

    public function test_full_quantity_pass_disposition_resolves_the_line(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(qaRequired: true);
        $lineId = $this->postedLine($order->lines()->sole()->id, $item->id, $uom->id, $this->locationId());

        $this->postDisposition($lineId, ['disposition' => 'PASS', 'qty' => '2.0000', 'reason' => 'inspected ok'])
            ->assertCreated()->assertJsonPath('data.remaining_qty', null);

        $this->receiptResponse($lineId)
            ->assertJsonPath('data.lines.0.qa_unallocated_qty', '0.0000')
            ->assertJsonPath('data.lines.0.qa_unresolved_qty', '0.0000');
    }

    public function test_partial_dispositions_sum_to_capacity_then_reject_excess(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(qaRequired: true, qty: '10.0000');
        $lineId = $this->postedLine($order->lines()->sole()->id, $item->id, $uom->id, $this->locationId(), ['received_qty' => '10.0000']);

        $this->postDisposition($lineId, ['disposition' => 'PASS', 'qty' => '6.0000', 'reason' => 'ok'])->assertCreated();
        $this->postDisposition($lineId, ['disposition' => 'ACCEPT_WITH_CONCESSION', 'qty' => '4.0000', 'reason' => 'minor defect'])->assertCreated();

        $this->postDisposition($lineId, ['disposition' => 'PASS', 'qty' => '0.0001', 'reason' => 'extra'])
            ->assertUnprocessable()->assertJsonValidationErrors('qty');
    }

    public function test_qa_unallocated_and_unresolved_quantities_diverge_through_a_rework_branch(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(qaRequired: true, qty: '10.0000');
        $lineId = $this->postedLine($order->lines()->sole()->id, $item->id, $uom->id, $this->locationId(), ['received_qty' => '10.0000']);

        $this->postDisposition($lineId, ['disposition' => 'ACCEPT_WITH_CONCESSION', 'qty' => '4.0000', 'reason' => 'ok'])->assertCreated();
        $rework = $this->postDisposition($lineId, ['disposition' => 'REWORK', 'qty' => '3.0000', 'reason' => 'needs rework'])->assertCreated();

        $this->receiptResponse($lineId)
            ->assertJsonPath('data.lines.0.qa_unallocated_qty', '3.0000')
            ->assertJsonPath('data.lines.0.qa_unresolved_qty', '6.0000');

        $reworkId = $rework->json('data.id');
        $this->postDisposition($lineId, ['disposition' => 'SCRAP', 'qty' => '2.0000', 'reason' => 'damaged', 'source_disposition_id' => $reworkId])->assertCreated();
        $this->postDisposition($lineId, ['disposition' => 'PASS', 'qty' => '1.0000', 'reason' => 'reworked ok', 'source_disposition_id' => $reworkId])->assertCreated();

        $this->receiptResponse($lineId)
            ->assertJsonPath('data.lines.0.qa_unallocated_qty', '3.0000')
            ->assertJsonPath('data.lines.0.qa_unresolved_qty', '3.0000');
    }

    public function test_posting_against_a_terminal_disposition_is_rejected(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(qaRequired: true);
        $lineId = $this->postedLine($order->lines()->sole()->id, $item->id, $uom->id, $this->locationId());

        $pass = $this->postDisposition($lineId, ['disposition' => 'PASS', 'qty' => '2.0000', 'reason' => 'ok'])->assertCreated();

        $this->postDisposition($lineId, ['disposition' => 'SCRAP', 'qty' => '1.0000', 'reason' => 'oops', 'source_disposition_id' => $pass->json('data.id')])
            ->assertUnprocessable()->assertJsonValidationErrors('source_disposition_id');
    }

    public function test_source_disposition_from_another_line_is_rejected(): void
    {
        $locationId = $this->locationId();
        [$orderA, $itemA, $uomA] = $this->issuedOrder(qaRequired: true);
        $lineA = $this->postedLine($orderA->lines()->sole()->id, $itemA->id, $uomA->id, $locationId);
        $reworkA = $this->postDisposition($lineA, ['disposition' => 'REWORK', 'qty' => '2.0000', 'reason' => 'needs work'])->assertCreated();

        [$orderB, $itemB, $uomB] = $this->issuedOrderB(qaRequired: true);
        $lineB = $this->postedLine($orderB->lines()->sole()->id, $itemB->id, $uomB->id, $locationId);

        $this->postDisposition($lineB, ['disposition' => 'PASS', 'qty' => '1.0000', 'reason' => 'x', 'source_disposition_id' => $reworkA->json('data.id')])
            ->assertUnprocessable()->assertJsonValidationErrors('source_disposition_id');
    }

    public function test_database_trigger_rejects_a_source_disposition_from_another_line(): void
    {
        $locationId = $this->locationId();
        [$orderA, $itemA, $uomA] = $this->issuedOrder(qaRequired: true);
        $lineA = $this->postedLine($orderA->lines()->sole()->id, $itemA->id, $uomA->id, $locationId);
        $reworkA = QualityDisposition::query()->create([
            'goods_receipt_line_id' => $lineA, 'disposition' => 'REWORK', 'qty' => '2.0000', 'reason' => 'x',
            'disposed_by' => User::factory()->create()->id, 'disposed_at' => now(),
        ]);

        [$orderB, $itemB, $uomB] = $this->issuedOrderB(qaRequired: true);
        $lineB = $this->postedLine($orderB->lines()->sole()->id, $itemB->id, $uomB->id, $locationId);

        $this->expectException(QueryException::class);
        DB::table('quality_dispositions')->insert([
            'company_id' => 1, 'goods_receipt_line_id' => $lineB, 'source_disposition_id' => $reworkA->id,
            'disposition' => 'PASS', 'qty' => '1.0000', 'reason' => 'bypass',
            'disposed_by' => User::factory()->create()->id, 'disposed_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_disposition_rejected_when_not_qa_required_or_receipt_not_posted(): void
    {
        $locationId = $this->locationId();
        [$order, $item, $uom] = $this->issuedOrder(qaRequired: false);
        $lineId = $this->postedLine($order->lines()->sole()->id, $item->id, $uom->id, $locationId);
        $this->postDisposition($lineId, ['disposition' => 'PASS', 'qty' => '2.0000', 'reason' => 'ok'])
            ->assertUnprocessable()->assertJsonValidationErrors('goods_receipt_line_id');

        [$order2, $item2, $uom2] = $this->issuedOrderB(qaRequired: true);
        $receiptId = $this->createReceipt($order2->lines()->sole()->id, $item2->id, $uom2->id, $locationId);
        $draftLineId = GoodsReceiptLine::query()->where('goods_receipt_id', $receiptId)->sole()->id;
        $this->postDisposition($draftLineId, ['disposition' => 'PASS', 'qty' => '2.0000', 'reason' => 'ok'])
            ->assertUnprocessable()->assertJsonValidationErrors('goods_receipt_line_id');
    }

    public function test_serial_tracked_line_accepts_exactly_one_root_disposition(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(qaRequired: true, tracking: ItemTracking::Serial, qty: '1.0000');
        $lineId = $this->postedLine($order->lines()->sole()->id, $item->id, $uom->id, $this->locationId(), [
            'received_qty' => '1.0000', 'serial_no' => 'SN-1',
        ]);

        $this->postDisposition($lineId, ['disposition' => 'PASS', 'qty' => '1.0000', 'reason' => 'ok'])->assertCreated();
        $this->postDisposition($lineId, ['disposition' => 'PASS', 'qty' => '1.0000', 'reason' => 'again'])
            ->assertUnprocessable()->assertJsonValidationErrors('qty');
    }

    public function test_posted_disposition_cannot_be_updated_via_eloquent(): void
    {
        $disposition = $this->makeDisposition();

        $this->expectException(DomainInvariantException::class);
        $disposition->update(['reason' => 'changed']);
    }

    public function test_posted_disposition_cannot_be_deleted_via_eloquent(): void
    {
        $disposition = $this->makeDisposition();

        $this->expectException(DomainInvariantException::class);
        $disposition->delete();
    }

    public function test_a_leaf_disposition_cannot_be_deleted_via_raw_sql(): void
    {
        $disposition = $this->makeDisposition();

        $this->expectException(QueryException::class);
        DB::table('quality_dispositions')->where('id', $disposition->id)->delete();
    }

    public function test_a_receipt_line_with_a_posted_disposition_cannot_be_deleted(): void
    {
        $disposition = $this->makeDisposition();

        $this->expectException(QueryException::class);
        DB::table('goods_receipt_lines')->where('id', $disposition->goods_receipt_line_id)->delete();
    }

    public function test_a_disposition_cannot_be_updated_via_raw_sql(): void
    {
        $disposition = $this->makeDisposition();

        $this->expectException(QueryException::class);
        DB::table('quality_dispositions')->where('id', $disposition->id)->update(['reason' => 'bypassed']);
    }

    public function test_database_check_rejects_an_invalid_disposition_value(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(qaRequired: true);
        $lineId = $this->postedLine($order->lines()->sole()->id, $item->id, $uom->id, $this->locationId());

        $this->expectException(QueryException::class);
        DB::table('quality_dispositions')->insert([
            'company_id' => 1, 'goods_receipt_line_id' => $lineId, 'disposition' => 'NOT_A_REAL_OUTCOME',
            'qty' => '1.0000', 'reason' => 'bad value', 'disposed_by' => User::factory()->create()->id,
            'disposed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_idempotency_key_retry_returns_same_row_but_rejects_a_different_payload(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(qaRequired: true);
        $lineId = $this->postedLine($order->lines()->sole()->id, $item->id, $uom->id, $this->locationId());
        $payload = ['disposition' => 'PASS', 'qty' => '2.0000', 'reason' => 'ok'];
        $headers = ['Idempotency-Key' => 'qa-disp-1'];

        $first = $this->postDisposition($lineId, $payload, $headers)->assertCreated();
        $second = $this->postDisposition($lineId, $payload, $headers)->assertCreated();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));

        $this->postDisposition($lineId, ['disposition' => 'SCRAP', 'qty' => '2.0000', 'reason' => 'changed'], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
    }

    public function test_index_paginates_sorts_deterministically_and_validates_the_query(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(qaRequired: true, qty: '10.0000');
        $lineId = $this->postedLine($order->lines()->sole()->id, $item->id, $uom->id, $this->locationId(), ['received_qty' => '10.0000']);

        $rework = $this->postDisposition($lineId, ['disposition' => 'REWORK', 'qty' => '4.0000', 'reason' => 'first'])->assertCreated();
        $this->postDisposition($lineId, ['disposition' => 'PASS', 'qty' => '6.0000', 'reason' => 'second'])->assertCreated();
        $this->postDisposition($lineId, ['disposition' => 'SCRAP', 'qty' => '4.0000', 'reason' => 'resolves the rework', 'source_disposition_id' => $rework->json('data.id')])->assertCreated();

        $response = $this->actingAs(User::factory()->create())
            ->getJson("/api/v1/goods-receipt-lines/{$lineId}/quality-dispositions?per_page=2")
            ->assertOk();
        $response->assertJsonPath('meta.per_page', 2)->assertJsonPath('meta.total', 3)->assertJsonCount(2, 'data');

        $reworkEntry = collect($response->json('data'))->firstWhere('id', $rework->json('data.id'));
        $this->assertSame('0.0000', $reworkEntry['remaining_qty'], 'the rework was fully resolved by the SCRAP disposition — proves the aggregate is real, not just eager-loading zero');

        $full = $this->actingAs(User::factory()->create())
            ->getJson("/api/v1/goods-receipt-lines/{$lineId}/quality-dispositions?sort=-qty")
            ->assertOk()->json('data');
        $this->assertSame(['6.0000', '4.0000', '4.0000'], array_column($full, 'qty'), 'sort=-qty must be descending, with a stable id tiebreaker for the equal-qty pair');

        $filtered = $this->actingAs(User::factory()->create())
            ->getJson("/api/v1/goods-receipt-lines/{$lineId}/quality-dispositions?filter[disposition]=PASS")
            ->assertOk()->json('data');
        $this->assertSame(['PASS'], array_unique(array_column($filtered, 'disposition')));
        $this->assertCount(1, $filtered);

        $this->actingAs(User::factory()->create())
            ->getJson("/api/v1/goods-receipt-lines/{$lineId}/quality-dispositions?filter[disposition]=BOGUS")
            ->assertUnprocessable()->assertJsonValidationErrors('filter.disposition');
        $this->actingAs(User::factory()->create())
            ->getJson("/api/v1/goods-receipt-lines/{$lineId}/quality-dispositions?sort=bogus")
            ->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->actingAs(User::factory()->create())
            ->getJson("/api/v1/goods-receipt-lines/{$lineId}/quality-dispositions?per_page=101")
            ->assertUnprocessable()->assertJsonValidationErrors('per_page');
    }

    public function test_cross_company_line_returns_not_found(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(qaRequired: true);
        $lineId = $this->postedLine($order->lines()->sole()->id, $item->id, $uom->id, $this->locationId());

        DB::table('companies')->insert(['id' => 2, 'name' => 'Company 2', 'created_at' => now(), 'updated_at' => now()]);
        $this->app->instance(CompanyContext::class, new class extends CompanyContext
        {
            public function id(): int
            {
                return 2;
            }
        });

        $this->postDisposition($lineId, ['disposition' => 'PASS', 'qty' => '1.0000', 'reason' => 'ok'])->assertNotFound();
    }

    public function test_actor_belonging_to_another_company_is_rejected(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(qaRequired: true);
        $lineId = $this->postedLine($order->lines()->sole()->id, $item->id, $uom->id, $this->locationId());
        $line = GoodsReceiptLine::query()->findOrFail($lineId);

        DB::table('companies')->insert(['id' => 2, 'name' => 'Company 2', 'created_at' => now(), 'updated_at' => now()]);
        $otherCompanyUserId = DB::table('users')->insertGetId([
            'company_id' => 2, 'name' => 'Other', 'email' => 'other@example.com', 'password' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(DomainInvariantException::class);
        app(PostQualityDisposition::class)($line, ['disposition' => 'PASS', 'qty' => '2.0000', 'reason' => 'ok'], $otherCompanyUserId);
    }

    public function test_database_trigger_rejects_an_actor_from_another_company(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(qaRequired: true);
        $lineId = $this->postedLine($order->lines()->sole()->id, $item->id, $uom->id, $this->locationId());

        DB::table('companies')->insert(['id' => 2, 'name' => 'Company 2', 'created_at' => now(), 'updated_at' => now()]);
        $otherCompanyUserId = DB::table('users')->insertGetId([
            'company_id' => 2, 'name' => 'Other', 'email' => 'other2@example.com', 'password' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('quality_dispositions')->insert([
            'company_id' => 1, 'goods_receipt_line_id' => $lineId, 'disposition' => 'PASS', 'qty' => '1.0000',
            'reason' => 'bypass', 'disposed_by' => $otherCompanyUserId, 'disposed_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Proves the actual mechanism PostQualityDisposition relies on to serialise the
     * whole disposition tree for one line: a real Postgres row lock on
     * goods_receipt_lines genuinely blocks a second session, the same technique
     * DefaultNumberingSchemeTest uses for the sequences row lock. Fixture rows are
     * created via raw autocommit PDO calls (not Eloquent) because RefreshDatabase
     * wraps this test in one uncommitted transaction on the app's own connection,
     * which these two genuinely separate PDO connections could never see.
     */
    public function test_a_second_transaction_is_blocked_by_the_line_lock_until_the_first_releases_it(): void
    {
        $config = config('database.connections.testing');
        $dsn = "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}";
        $pdoA = new PDO($dsn, $config['username'], $config['password']);
        $pdoB = new PDO($dsn, $config['username'], $config['password']);

        $this->cleanUpRawFixture($pdoA, 'qa_lock_test', 'QLT');
        [$lineId] = $this->createRawFixture($pdoA, 1, 'qa_lock_test', 'QLT', qaRequired: false, receiptStatus: 'DRAFT');

        try {
            $pdoA->beginTransaction();
            $pdoA->query("select id from goods_receipt_lines where id = {$lineId} for update");

            $pdoB->beginTransaction();
            $pdoB->exec("set local lock_timeout = '200ms'");

            $this->expectException(PDOException::class);
            $pdoB->query("select id from goods_receipt_lines where id = {$lineId} for update");
        } finally {
            $pdoA->rollBack();
            $pdoB->rollBack();
            $this->cleanUpRawFixture($pdoA, 'qa_lock_test', 'QLT');
        }
    }

    /**
     * Proves PostQualityDisposition's capacity check holds under real concurrency —
     * not just that the underlying row lock blocks (the test above), but that the
     * Action itself, invoked from two genuinely separate OS processes racing for the
     * same line, never lets combined allocation exceed capacity. pcntl_fork() gives
     * two real processes; each needs its own fixture visible across process
     * boundaries, so — same reasoning as the lock test above — the fixture is built
     * via raw, immediately-committed PDO inserts rather than Eloquent.
     *
     * Like the lock test, this fixture is removed afterwards — it commits outside RefreshDatabase's transaction, so anything left would leak into the shared
     * test database (a stray currency breaks CurrencyApiTest's exact count; a stray company id collides with tests that insert one). The winning process
     * creates a real, posted QualityDisposition, which the database makes undeletable (prevent_quality_disposition_delete) along with the chain it pins, so
     * cleanUpRawFixture($committedHistory: true) switches off the user triggers on exactly those tables for the delete and switches them back on.
     *
     * The fixture still lives in its own company rather than company 1, so an exact-count assertion on company-1 data cannot see it while it exists; a random
     * per-run suffix keeps repeated or interrupted runs from colliding. currencies has no company scope at all — the one genuinely global table this touches.
     */
    public function test_concurrent_dispositions_from_separate_processes_cannot_together_exceed_capacity(): void
    {
        $config = config('database.connections.testing');
        $pdo = new PDO(
            "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}",
            $config['username'], $config['password']
        );
        $companyId = $this->resolveIsolatedTestCompanyId($pdo);
        $suffix = bin2hex(random_bytes(4));
        [$lineId, $actorId] = $this->createRawFixture($pdo, $companyId, "qa_concurrency_test_{$suffix}", 'QAX', qaRequired: true, receiptStatus: 'POSTED');
        $permission = $pdo->prepare(<<<'SQL'
            INSERT INTO permissions (name, guard_name, created_at, updated_at)
            VALUES ('purchasing.quality-disposition.post', 'web', NOW(), NOW())
            ON CONFLICT (name, guard_name) DO UPDATE SET updated_at = EXCLUDED.updated_at
            RETURNING id
            SQL);
        $permission->execute();
        $permissionId = (int) $permission->fetchColumn();
        $grant = $pdo->prepare(<<<'SQL'
            INSERT INTO model_has_permissions (permission_id, model_type, model_id, team_id)
            VALUES (:permission_id, :model_type, :model_id, :team_id)
            ON CONFLICT DO NOTHING
            SQL);
        $grant->execute([
            'permission_id' => $permissionId,
            'model_type' => User::class,
            'model_id' => $actorId,
            'team_id' => $companyId,
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Every model involved resolves its tenant scope through CompanyContext, not
        // a raw column check — both processes need it pointed at the isolated
        // company above, or GoodsReceiptLine::findOrFail() 404s under the default
        // company-1 context. Set before forking so the child inherits it too.
        $this->app->instance(CompanyContext::class, new class($companyId) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });
        app(PermissionRegistrar::class)->setPermissionsTeamId($companyId);
        $resultPath = sys_get_temp_dir().'/qa_disposition_concurrency_'.getmypid().'.json';
        $startPath = sys_get_temp_dir().'/qa_disposition_concurrency_'.getmypid().'.start';
        @unlink($resultPath);
        @unlink($startPath);

        try {
            $childPid = pcntl_fork();
            if ($childPid === -1) {
                $this->fail('Unable to fork a process for the concurrency test.');
            }

            if ($childPid === 0) {
                // The child inherits the parent's already-open 'testing' PDO as a
                // duplicated file descriptor over the same underlying TCP socket.
                // Postgres sees one client stream regardless of which process's copy
                // touches it — DB::purge()/reconnect() here would gracefully close
                // that inherited PDO, sending a real Terminate message down the
                // *shared* socket and killing the parent's connection too (this is
                // exactly what broke the parent with "server closed the connection
                // unexpectedly" before this was fixed). So this process must never
                // reference the 'testing' connection at all — it gets its own,
                // separately named connection instead, and exits via SIGKILL rather
                // than exit()/return so PHP's normal shutdown sequence never runs a
                // destructor against the untouched, inherited 'testing' PDO object
                // still sitting in this process's memory.
                config(['database.connections.qa_disposition_child' => config('database.connections.testing')]);
                config(['database.default' => 'qa_disposition_child']);

                $waited = 0;
                while (! file_exists($startPath) && $waited < 2_000_000) {
                    usleep(1000);
                    $waited += 1000;
                }

                try {
                    $line = GoodsReceiptLine::query()->findOrFail($lineId);
                    app(PostQualityDisposition::class)($line, ['disposition' => 'PASS', 'qty' => '6.0000', 'reason' => 'child'], $actorId);
                    file_put_contents($resultPath, json_encode(['success' => true]));
                } catch (\Throwable $e) {
                    file_put_contents($resultPath, json_encode(['success' => false, 'message' => $e->getMessage()]));
                }

                posix_kill(getmypid(), SIGKILL);
            }

            // The parent must not run its own call on the ambient 'testing'
            // connection either: RefreshDatabase already holds that connection open
            // in an outer transaction for the whole test, so the Action's own
            // DB::transaction() would just open a SAVEPOINT — and a savepoint release
            // does not release a row lock, only the enclosing real transaction's
            // commit does. Without this, the parent's lockForUpdate() would hold the
            // row forever (the outer transaction never commits mid-test), the child
            // would block on it forever, and pcntl_waitpid() below would hang
            // forever — a real deadlock this test hit before this fix. A second,
            // separately-named connection gives the parent's call a real,
            // independently-committing transaction, exactly like the child's.
            $originalDefaultConnection = config('database.default');
            config(['database.connections.qa_disposition_parent' => config('database.connections.testing')]);
            config(['database.default' => 'qa_disposition_parent']);

            $line = GoodsReceiptLine::query()->findOrFail($lineId);
            file_put_contents($startPath, '1');

            try {
                app(PostQualityDisposition::class)($line, ['disposition' => 'ACCEPT_WITH_CONCESSION', 'qty' => '6.0000', 'reason' => 'parent'], $actorId);
                $parentOutcome = ['success' => true];
            } catch (\Throwable $e) {
                $parentOutcome = ['success' => false, 'message' => $e->getMessage()];
            } finally {
                config(['database.default' => $originalDefaultConnection]);
                DB::purge('qa_disposition_parent');
            }

            pcntl_waitpid($childPid, $status);
            $childOutcome = json_decode((string) file_get_contents($resultPath), true);
            $this->assertIsArray($childOutcome, 'the forked child did not report an outcome');

            $this->assertNotSame(
                $parentOutcome['success'], $childOutcome['success'],
                'exactly one of two concurrent 6-unit dispositions against a 10-unit capacity must fail'
            );
            $failure = $parentOutcome['success'] ? $childOutcome : $parentOutcome;
            $this->assertStringContainsString('exceeds pending QA quantity', $failure['message']);

            $totalAllocated = DB::connection('testing')->table('quality_dispositions')->where('goods_receipt_line_id', $lineId)->sum('qty');
            $this->assertSame('6.0000', number_format((float) $totalAllocated, 4, '.', ''), 'only the winning 6-unit disposition may be posted — the loser must add no quantity at all');
        } finally {
            @unlink($resultPath);
            @unlink($startPath);
            // Committed outside RefreshDatabase's transaction, so removed here: a leftover currency, company or posted chain would change every later
            // test's view of the shared database (see CurrencyApiTest's exact currency count).
            $this->cleanUpRawFixture($pdo, "qa_concurrency_test_{$suffix}", 'QAX', committedHistory: true);
            $pdo->exec("delete from activity_log where company_id = {$companyId}");
            $pdo->exec("delete from companies where id = {$companyId}");
        }

        $this->assertFalse(DB::connection('testing')->table('currencies')->where('code', 'QAX')->exists(), 'the raw fixture left its currency in the shared test database');
        $this->assertFalse(DB::connection('testing')->table('companies')->where('id', $companyId)->exists(), 'the raw fixture left its company in the shared test database');
    }

    /**
     * A dedicated company, created once and reused across runs (matched by a fixed
     * marker name), isolates a fixture that can never be cleaned up from every other
     * test's company-1 exact-count assertions — see the concurrency test's docblock.
     */
    private function resolveIsolatedTestCompanyId(PDO $pdo): int
    {
        $name = 'QA Disposition Concurrency Test Company';
        $pdo->exec("insert into companies (name, created_at, updated_at) select '{$name}', now(), now() where not exists (select 1 from companies where name = '{$name}')");

        return (int) $pdo->query("select id from companies where name = '{$name}'")->fetchColumn();
    }

    /** @return array{0: int, 1: int} [goods_receipt_line_id, user_id] */
    private function createRawFixture(PDO $pdo, int $companyId, string $prefix, string $currencyCode, bool $qaRequired, string $receiptStatus): array
    {
        // currencies has no company scope at all, so — unlike everything else this
        // creates — it's resolved idempotently by code rather than always inserted,
        // to avoid ever growing that genuinely global table across repeated runs.
        $pdo->exec("insert into currencies (code, name, symbol, decimal_places, created_at, updated_at) select '{$currencyCode}', '{$currencyCode} Currency', 'Q', 2, now(), now() where not exists (select 1 from currencies where code = '{$currencyCode}')");
        $currencyId = (int) $pdo->query("select id from currencies where code = '{$currencyCode}'")->fetchColumn();

        $uomId = (int) $pdo->query("insert into uoms (company_id, code, name, dimension, factor_to_base, is_base, created_at, updated_at) values ({$companyId}, '{$prefix}_ea', '{$prefix} Each', 'COUNT', 1, false, now(), now()) returning id")->fetchColumn();
        $acctId = (int) $pdo->query("insert into chart_of_accounts (company_id, code, name, type, is_postable, status, created_at, updated_at) values ({$companyId}, '{$prefix}-pay', '{$prefix} Payable', 'LIABILITY', true, 'ACTIVE', now(), now()) returning id")->fetchColumn();
        $catId = (int) $pdo->query("insert into categories (company_id, code, name, created_at, updated_at) values ({$companyId}, '{$prefix}_cat', '{$prefix} Category', now(), now()) returning id")->fetchColumn();
        $itemId = (int) $pdo->query("insert into items (company_id, code, name, type, stock_uom_id, is_stocked, valuation_method, tracking, category_id, status, created_at, updated_at) values ({$companyId}, '{$prefix}_item', '{$prefix} Item', 'RAW', {$uomId}, true, 'FIFO', 'NONE', {$catId}, 'ACTIVE', now(), now()) returning id")->fetchColumn();
        $warehouseId = (int) $pdo->query("insert into warehouses (company_id, code, name, is_default, active, created_at, updated_at) values ({$companyId}, '{$prefix}_wh', '{$prefix} Warehouse', false, true, now(), now()) returning id")->fetchColumn();
        $locationId = (int) $pdo->query("insert into locations (warehouse_id, code, name, active, created_at, updated_at) values ({$warehouseId}, '{$prefix}_loc', '{$prefix} Location', true, now(), now()) returning id")->fetchColumn();
        $partyId = (int) $pdo->query("insert into parties (company_id, code, name, status, created_at, updated_at) values ({$companyId}, '{$prefix}_party', '{$prefix} Party', 'ACTIVE', now(), now()) returning id")->fetchColumn();
        $supplierId = (int) $pdo->query("insert into supplier_accounts (company_id, party_id, code, payment_terms_days, payable_account_id, status, created_at, updated_at) values ({$companyId}, {$partyId}, '{$prefix}_sup', 30, {$acctId}, 'ACTIVE', now(), now()) returning id")->fetchColumn();
        $userId = (int) $pdo->query("insert into users (company_id, name, email, password, created_at, updated_at) values ({$companyId}, '{$prefix} User', '{$prefix}@example.com', 'x', now(), now()) returning id")->fetchColumn();
        $orderId = (int) $pdo->query("insert into purchase_orders (company_id, supplier_account_id, currency_id, fx_rate, fx_rate_date, fx_rate_type, fx_rate_source, order_date, created_at, updated_at) values ({$companyId}, {$supplierId}, {$currencyId}, 1, current_date, 'SPOT', 'manual', current_date, now(), now()) returning id")->fetchColumn();
        $qaRequiredSql = $qaRequired ? 'true' : 'false';
        $orderLineId = (int) $pdo->query("insert into purchase_order_lines (company_id, purchase_order_id, line_number, item_id, description, ordered_qty, purchase_uom_id, ordered_stock_qty, stock_uom_id, uom_factor, unit_price, line_amount, base_unit_price, base_line_amount, qa_required, created_at, updated_at) values ({$companyId}, {$orderId}, 1, {$itemId}, '{$prefix} line', 10, {$uomId}, 10, {$uomId}, 1, 10, 100, 10, 100, {$qaRequiredSql}, now(), now()) returning id")->fetchColumn();
        // Lines can only ever be added to a draft receipt (goods_receipt_lines_guard),
        // so a fabricated posted receipt is built the way a real one is: draft first,
        // line added, then the status transition.
        $receiptId = (int) $pdo->query("insert into goods_receipts (company_id, supplier_account_id, status, physical_arrival_at, recorded_at, posting_date, created_at, updated_at) values ({$companyId}, {$supplierId}, 'DRAFT', now(), now(), current_date, now(), now()) returning id")->fetchColumn();

        $lineId = (int) $pdo->query(
            'insert into goods_receipt_lines (goods_receipt_id, purchase_order_line_id, item_id, location_id, received_qty, received_uom_id, '.
            'stock_qty, stock_uom_id, uom_factor, provisional_unit_cost, currency_id, fx_rate, base_unit_cost, fx_rate_date, fx_rate_type, fx_rate_source, created_at, updated_at) '.
            "values ({$receiptId}, {$orderLineId}, {$itemId}, {$locationId}, 10, {$uomId}, 10, {$uomId}, 1, 10, {$currencyId}, 1, 10, current_date, 'SPOT', 'manual', now(), now()) returning id"
        )->fetchColumn();
        if ($receiptStatus !== 'DRAFT') {
            $pdo->exec("update goods_receipts set status = '{$receiptStatus}', posted_at = now() where id = {$receiptId}");
        }

        return [$lineId, $userId];
    }

    /**
     * Removes everything createRawFixture() committed. $committedHistory is for the fixture whose dispositions and posted receipt are database-enforced
     * history: the guards on exactly those tables are switched off for the delete only (the same technique the other raw-PDO cleanups use) and always
     * switched back on.
     */
    private function cleanUpRawFixture(PDO $pdo, string $prefix, string $currencyCode, bool $committedHistory = false): void
    {
        $guarded = ['quality_dispositions', 'goods_receipt_lines', 'goods_receipts', 'purchase_order_lines', 'purchase_orders'];
        if ($committedHistory) {
            foreach ($guarded as $table) {
                $pdo->exec("alter table {$table} disable trigger user");
            }
        }

        try {
            $this->deleteRawFixtureRows($pdo, $prefix, $currencyCode);
        } finally {
            if ($committedHistory) {
                foreach ($guarded as $table) {
                    $pdo->exec("alter table {$table} enable trigger user");
                }
            }
        }
    }

    private function deleteRawFixtureRows(PDO $pdo, string $prefix, string $currencyCode): void
    {
        $pdo->exec("delete from model_has_permissions where model_type = '".addslashes(User::class)."' and model_id in (select id from users where email = '{$prefix}@example.com')");
        $pdo->exec("delete from quality_dispositions where goods_receipt_line_id in (select id from goods_receipt_lines where item_id in (select id from items where code = '{$prefix}_item'))");
        $pdo->exec("delete from goods_receipt_lines where item_id in (select id from items where code = '{$prefix}_item')");
        $pdo->exec("delete from goods_receipts where supplier_account_id in (select id from supplier_accounts where code = '{$prefix}_sup')");
        $pdo->exec("delete from purchase_order_lines where item_id in (select id from items where code = '{$prefix}_item')");
        $pdo->exec("delete from purchase_orders where supplier_account_id in (select id from supplier_accounts where code = '{$prefix}_sup')");
        $pdo->exec("delete from supplier_accounts where code = '{$prefix}_sup'");
        $pdo->exec("delete from users where email = '{$prefix}@example.com'");
        $pdo->exec("delete from parties where code = '{$prefix}_party'");
        $pdo->exec("delete from locations where code = '{$prefix}_loc'");
        $pdo->exec("delete from warehouses where code = '{$prefix}_wh'");
        $pdo->exec("delete from items where code = '{$prefix}_item'");
        $pdo->exec("delete from categories where code = '{$prefix}_cat'");
        $pdo->exec("delete from chart_of_accounts where code = '{$prefix}-pay'");
        $pdo->exec("delete from uoms where code = '{$prefix}_ea'");
        $pdo->exec("delete from currencies where code = '{$currencyCode}'");
    }

    private function makeDisposition(): QualityDisposition
    {
        [$order, $item, $uom] = $this->issuedOrder(qaRequired: true);
        $lineId = $this->postedLine($order->lines()->sole()->id, $item->id, $uom->id, $this->locationId());

        return QualityDisposition::query()->create([
            'goods_receipt_line_id' => $lineId, 'disposition' => 'PASS', 'qty' => '2.0000', 'reason' => 'ok',
            'disposed_by' => User::factory()->create()->id, 'disposed_at' => now(),
        ]);
    }

    private function postDisposition(int $lineId, array $payload, array $headers = []): TestResponse
    {
        return $this->actingAs(User::factory()->create())
            ->postJson("/api/v1/goods-receipt-lines/{$lineId}/quality-dispositions", $payload, $headers);
    }

    private function receiptResponse(int $lineId): TestResponse
    {
        $receiptId = GoodsReceiptLine::query()->findOrFail($lineId)->goods_receipt_id;

        return $this->actingAs(User::factory()->create())->getJson("/api/v1/goods-receipts/{$receiptId}");
    }

    /** @return array{PurchaseOrder, Item, Uom} */
    private function issuedOrder(bool $qaRequired, ValuationMethod $method = ValuationMethod::Avco, ItemTracking $tracking = ItemTracking::None, string $qty = '2.0000'): array
    {
        [$order, $item, $uom] = $this->makeOrder();
        $item->update([
            'is_stocked' => true, 'valuation_method' => $method, 'tracking' => $tracking,
            'avco_pool_scope' => $method === ValuationMethod::Avco ? AvcoPoolScope::CompanyItem : null,
            'inventory_account_id' => $item->category->resolveInventoryAccountId(),
        ]);
        if ($item->inventory_account_id === null) {
            $item->update(['inventory_account_id' => ChartOfAccount::query()->firstOrFail()->id]);
        }
        $order->lines()->create(array_merge($this->lineAttributes($order, $item, $uom), [
            'ordered_qty' => $qty, 'ordered_stock_qty' => $qty,
            'line_amount' => (string) ((int) (float) $qty * 10).'.0000',
            'qa_required' => $qaRequired,
        ]));
        app(IssuePurchaseOrder::class)($order, $this->purchasingActor());

        return [$order->refresh(), $item->refresh(), $uom];
    }

    /**
     * A second, independently-coded order/item/uom/supplier — issuedOrder() reuses
     * PurchaseOrderFoundationTest::makeOrder()'s hardcoded 'SUP'/'ea'/'raw'/'item'
     * codes, which collide on a second call within the same test.
     *
     * @return array{PurchaseOrder, Item, Uom}
     */
    private function issuedOrderB(bool $qaRequired, string $qty = '2.0000'): array
    {
        $currency = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$']);
        $party = Party::create(['code' => 'SUP2', 'name' => 'SUP2']);
        $account = ChartOfAccount::create(['code' => 'pay-SUP2', 'name' => 'Payable SUP2', 'type' => AccountType::Liability]);
        $supplier = SupplierAccount::create(['party_id' => $party->id, 'code' => 'SUP2', 'payment_terms_days' => 30, 'payable_account_id' => $account->id]);
        $partySite = PartySite::create(['party_id' => $party->id, 'code' => 'main', 'name' => 'Main']);
        $site = SupplierSite::create(['supplier_account_id' => $supplier->id, 'party_site_id' => $partySite->id, 'role' => 'ORDER_FROM']);
        $uom = Uom::create(['code' => 'ea2', 'name' => 'Each 2', 'dimension' => UomDimension::Count, 'factor_to_base' => '1']);
        $category = Category::create(['code' => 'raw2', 'name' => 'Raw 2', 'default_valuation_method' => 'AVCO']);
        $item = Item::create(['code' => 'item2', 'name' => 'Item 2', 'type' => 'RAW', 'stock_uom_id' => $uom->id, 'category_id' => $category->id]);
        $order = PurchaseOrder::create([
            'supplier_account_id' => $supplier->id, 'supplier_site_id' => $site->id,
            'currency_id' => $currency->id, 'fx_rate' => '1.000000000',
            'fx_rate_date' => '2026-09-02', 'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual',
            'order_date' => '2026-09-02',
        ])->refresh();

        $item->update([
            'is_stocked' => true, 'valuation_method' => ValuationMethod::Avco, 'tracking' => ItemTracking::None,
            'avco_pool_scope' => AvcoPoolScope::CompanyItem,
            'inventory_account_id' => $item->category->resolveInventoryAccountId(),
        ]);
        if ($item->inventory_account_id === null) {
            $item->update(['inventory_account_id' => ChartOfAccount::query()->firstOrFail()->id]);
        }
        $order->lines()->create(array_merge($this->lineAttributes($order, $item, $uom), [
            'ordered_qty' => $qty, 'ordered_stock_qty' => $qty,
            'line_amount' => (string) ((int) (float) $qty * 10).'.0000',
            'qa_required' => $qaRequired,
        ]));
        app(IssuePurchaseOrder::class)($order, $this->purchasingActor());

        return [$order->refresh(), $item->refresh(), $uom];
    }

    private function locationId(): int
    {
        $warehouse = app(CreateWarehouse::class)([
            'code' => 'main', 'name' => 'Main', 'location_code' => 'main', 'location_name' => 'Main',
        ], PurchasingActor::make(['inventory.warehouse.manage']));

        return $warehouse->locations()->sole()->id;
    }

    private function createReceipt(int $poLineId, int $itemId, int $uomId, int $locationId, array $overrides = []): int
    {
        $poLine = PurchaseOrderLine::query()->findOrFail($poLineId);
        $payload = [
            'supplier_account_id' => $poLine->purchaseOrder->supplier_account_id,
            'physical_arrival_at' => now()->toIso8601String(), 'posting_date' => now()->format('Y-m-d'),
            'lines' => [array_merge([
                'purchase_order_line_id' => $poLineId, 'item_id' => $itemId, 'location_id' => $locationId,
                'received_qty' => '2.0000', 'received_uom_id' => $uomId, 'provisional_unit_cost' => '10.0000',
                'currency_id' => $poLine->purchaseOrder->currency_id, 'fx_rate' => '1.000000000',
                'fx_rate_date' => now()->format('Y-m-d'), 'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual',
            ], $overrides)],
        ];

        return $this->actingAs(User::factory()->create())->postJson('/api/v1/goods-receipts', $payload)
            ->assertCreated()->json('data.id');
    }

    private function postedLine(int $poLineId, int $itemId, int $uomId, int $locationId, array $overrides = []): int
    {
        $receiptId = $this->createReceipt($poLineId, $itemId, $uomId, $locationId, $overrides);
        $this->actingAs(User::factory()->create())->postJson("/api/v1/goods-receipts/{$receiptId}/post")->assertOk();

        return GoodsReceiptLine::query()->where('goods_receipt_id', $receiptId)->sole()->id;
    }
}
