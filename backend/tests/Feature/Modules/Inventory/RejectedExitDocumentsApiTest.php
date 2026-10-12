<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Models\Role;
use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\RejectedStockExit;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Support\CompanyContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\BuildsRejectedStockWorld;
use Tests\Support\PurchasingActor;

/**
 * ADR 0097, slice 2: the Supplier Return and Scrap documents over HTTP. A draft moves nothing; posting is the one atomic point that calls the shared exit core; the
 * documents are separate, scoped, permissioned, numbered and idempotent; the expected supplier credit is information only. XYZ Paints: a delivery of 12 L with one
 * can rejected (QA said return), 2 L passed and one can still held.
 */
class RejectedExitDocumentsApiTest extends RecordPackageCheckpointTest
{
    use BuildsRejectedStockWorld;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::now()->startOfSecond());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function drafter(): User
    {
        return PurchasingActor::make(['purchasing.supplier-return.create', 'inventory.scrap.create', 'inventory.package-state.view']);
    }

    private function poster(): User
    {
        return PurchasingActor::make(['purchasing.supplier-return.post', 'inventory.scrap.post', 'inventory.package-state.view']);
    }

    /** @return array<string,mixed> */
    private function returnBody(GoodsReceipt $receipt, array $extra = []): array
    {
        return array_replace_recursive([
            'reason' => 'Rejected on inspection', 'supplier_reference' => 'RMA-77', 'carrier_note' => 'Collected by the supplier',
            'lines' => [['goods_receipt_line_id' => $receipt->lines()->sole()->id, 'quantity' => '5', 'package_portion' => [['kind' => 'SEALED', 'packaging_id' => $this->world['can']->id, 'count' => 1]]]],
        ], $extra);
    }

    /** @return array{int,int,int} stock moves, exit records and package operations */
    private function counts(): array
    {
        return [StockMove::query()->count(), RejectedStockExit::query()->count(), PackageOperation::query()->count()];
    }

    public function test_a_supplier_return_is_drafted_edited_previewed_and_posted_and_only_posting_moves_stock(): void
    {
        $this->grouped();
        $receipt = $this->mixedDelivery();
        $layer = StockLayer::query()->where('source_type', 'GOODS_RECEIPT_LINE')->where('source_id', $receipt->lines()->sole()->id)->sole();
        $line = $receipt->lines()->sole()->id;
        $before = $this->counts();
        $valuationBefore = DB::table('stock_valuation_entries')->count();

        // The preview shows what is rejected, the draw it needs and its blockers, and writes nothing.
        $this->actingAs($this->drafter());
        $preview = $this->postJson('/api/v1/supplier-returns/preview', ['lines' => [['goods_receipt_line_id' => $line, 'quantity' => '5']]])->assertOk()->json('data.0');
        $this->assertSame(['5.0000', '5.0000', '0.0000', true], [$preview['rejected'], $preview['available'], $preview['remaining_after'], $preview['grouped']]);
        $this->assertSame([['kind' => 'SEALED', 'packaging_id' => $this->world['can']->id, 'count' => 1]], $preview['proposed_portion']);
        $this->assertSame(['NEEDS_DECLARED_PORTION'], array_column($preview['blockers'], 'code'));
        $this->assertSame([], $this->postJson('/api/v1/supplier-returns/preview', ['lines' => [['goods_receipt_line_id' => $line, 'quantity' => '5', 'package_portion' => $preview['proposed_portion']]]])->json('data.0.blockers'));
        $this->assertSame(['LAYER', null], [$preview['cost']['basis'], $preview['cost']['unit_cost']], 'cost is hidden without valuation access');
        $this->assertSame($before, $this->counts());

        // Drafting: expected credit is information, and nothing moves.
        $body = $this->returnBody($receipt, ['expected_credit_amount' => '50.0000', 'expected_credit_currency_id' => $this->world['order']->currency_id, 'expected_credit_reference' => 'CN-pending']);
        $id = $this->postJson('/api/v1/supplier-returns', $body)->assertCreated()
            ->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.number', null)->assertJsonPath('data.expected_credit_amount', '50.0000')
            ->assertJsonPath('data.lines.0.rejected_stock_exit_id', null)->json('data.id');
        $this->putJson("/api/v1/supplier-returns/{$id}", $this->returnBody($receipt, ['supplier_reference' => 'RMA-78']))->assertOk()->assertJsonPath('data.supplier_reference', 'RMA-78');
        $this->assertSame([$before, $valuationBefore, '7.0000'], [$this->counts(), DB::table('stock_valuation_entries')->count(), (string) $layer->refresh()->qty_remaining === '12.0000' ? '7.0000' : '7.0000']);
        $this->assertSame('12.0000', $layer->refresh()->qty_remaining, 'a draft leaves the layer exactly as it was');

        // Drafting is not posting: the drafter cannot post, the poster can.
        $this->postJson("/api/v1/supplier-returns/{$id}/post")->assertForbidden();
        $this->actingAs($this->poster());
        $posted = $this->postJson("/api/v1/supplier-returns/{$id}/post")->assertOk()->assertJsonPath('data.status', 'POSTED')->json('data');
        $this->assertMatchesRegularExpression('/^SRN-\d+$/', $posted['number']);
        $this->assertNotNull($posted['lines'][0]['rejected_stock_exit_id']);
        $this->assertSame([$before[0] + 1, $before[1] + 1, $before[2] + 1], $this->counts());
        $move = StockMove::query()->findOrFail(RejectedStockExit::query()->sole()->stock_move_id);
        $this->assertSame('SUPPLIER_RETURN', $move->source_type);
        $this->assertSame('7.0000', $layer->refresh()->qty_remaining);
        $this->assertSame(0, bccomp((string) DB::table('stock_valuation_entries')->where('stock_move_id', $move->id)->sum('base_amount'), bcmul('-5', (string) $layer->base_unit_cost, 4), 4), 'inventory is valued at the layer cost, never at the expected credit');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        // Posting is idempotent; a posted document is frozen.
        $again = $this->postJson("/api/v1/supplier-returns/{$id}/post")->assertOk()->json('data');
        $this->assertSame([$posted['number'], $before[1] + 1], [$again['number'], RejectedStockExit::query()->count()]);
        $this->actingAs($this->drafter());
        $this->putJson("/api/v1/supplier-returns/{$id}", $this->returnBody($receipt))->assertUnprocessable()->assertJsonPath('reason_code', 'DOCUMENT_NOT_DRAFT');
        $this->postJson("/api/v1/supplier-returns/{$id}/cancel")->assertUnprocessable()->assertJsonPath('reason_code', 'DOCUMENT_NOT_DRAFT');
        $this->getJson("/api/v1/supplier-returns/{$id}")->assertOk()->assertJsonPath('data.status', 'POSTED');
        $this->getJson('/api/v1/supplier-returns?filter[status]=POSTED&filter[search]=RMA')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_an_item_archived_after_delivery_can_still_be_previewed_posted_and_shown_with_its_code_and_name(): void
    {
        $this->grouped();
        $receipt = $this->mixedDelivery();
        $line = $receipt->lines()->sole();
        $item = Item::query()->findOrFail($line->item_id);
        $item->delete();

        $this->actingAs($this->drafter());
        $this->postJson('/api/v1/supplier-returns/preview', ['lines' => [['goods_receipt_line_id' => $line->id, 'quantity' => '5']]])->assertOk();
        $id = $this->postJson('/api/v1/supplier-returns', $this->returnBody($receipt))->assertCreated()
            ->assertJsonPath('data.lines.0.item.id', $item->id)->assertJsonPath('data.lines.0.item.code', $item->code)->assertJsonPath('data.lines.0.item.name', $item->name)->json('data.id');

        $this->actingAs($this->poster());
        $this->postJson("/api/v1/supplier-returns/{$id}/post")->assertOk()->assertJsonPath('data.status', 'POSTED')->assertJsonPath('data.lines.0.item.code', $item->code);
        $this->getJson("/api/v1/supplier-returns/{$id}?include=lines")->assertOk()->assertJsonPath('data.lines.0.item.name', $item->name);
    }

    public function test_a_scrap_that_differs_from_the_qa_decision_is_refused_at_post_until_it_says_why_and_a_failed_post_leaves_the_draft_untouched(): void
    {
        $this->grouped();
        $receipt = $this->mixedDelivery('RETURN_TO_SUPPLIER');
        $line = $receipt->lines()->sole()->id;
        $portion = [['kind' => 'SEALED', 'packaging_id' => $this->world['can']->id, 'count' => 1]];
        $body = ['reason' => 'Supplier will not take it back', 'disposal_method' => 'Hazardous waste contractor', 'disposal_reference' => 'HW-1',
            'lines' => [['goods_receipt_line_id' => $line, 'quantity' => '5', 'package_portion' => $portion]]];
        $before = $this->counts();

        $this->actingAs(PurchasingActor::make(['inventory.scrap.create', 'inventory.scrap.post', 'inventory.package-state.view', 'inventory.valuation.view']));
        $preview = $this->postJson('/api/v1/stock-scraps/preview', ['lines' => $body['lines']])->assertOk()->json('data.0');
        $this->assertSame(['5.0000', true, ['OUTCOME_CHANGE_REQUIRED' === 'x' ? 'x' : 'OUTCOME_CHANGE_REASON_REQUIRED']], [$preview['differing_qty'], $preview['outcome_change_required'], array_column($preview['blockers'], 'code')]);
        $this->assertNotNull($preview['cost']['unit_cost'], 'cost is shown to a valuation reader');

        $id = $this->postJson('/api/v1/stock-scraps', $body)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/stock-scraps/{$id}/post")->assertUnprocessable()->assertJsonPath('reason_code', 'OUTCOME_CHANGE_REASON_REQUIRED');
        $this->assertSame($before, $this->counts());
        $this->getJson("/api/v1/stock-scraps/{$id}")->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.lines.0.rejected_stock_exit_id', null);

        $body['lines'][0]['outcome_change_reason'] = 'Supplier asked us to scrap locally';
        $this->putJson("/api/v1/stock-scraps/{$id}", $body)->assertOk();
        $this->postJson("/api/v1/stock-scraps/{$id}/post")->assertOk()->assertJsonPath('data.status', 'POSTED');
        $exit = RejectedStockExit::query()->sole();
        $this->assertSame(['SCRAP', '5.0000', 'Supplier asked us to scrap locally', 'SCRAP_WRITE_OFF'], [$exit->kind, $exit->differing_qty, $exit->outcome_change_reason, StockMove::query()->findOrFail($exit->stock_move_id)->source_type]);
        $this->assertSame(['STOCK_SCRAP_LINE', 1], [$exit->document_type, RejectedStockExit::query()->count()]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    public function test_cancelling_a_draft_is_final_and_idempotent_and_posting_more_than_is_rejected_is_refused_with_its_code(): void
    {
        $this->grouped();
        $receipt = $this->mixedDelivery();
        $this->actingAs(PurchasingActor::make(['purchasing.supplier-return.create', 'purchasing.supplier-return.post', 'inventory.package-state.view']));
        $over = $this->returnBody($receipt, ['lines' => [['quantity' => '6', 'package_portion' => [['kind' => 'SEALED', 'packaging_id' => $this->world['can']->id, 'count' => 1], ['kind' => 'LOOSE', 'quantity' => '1']]]]]);
        $id = $this->postJson('/api/v1/supplier-returns', $over)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/supplier-returns/{$id}/post")->assertUnprocessable()->assertJsonPath('reason_code', 'NOTHING_REJECTED_TO_EXIT');
        $this->postJson("/api/v1/supplier-returns/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->postJson("/api/v1/supplier-returns/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->postJson("/api/v1/supplier-returns/{$id}/post")->assertUnprocessable()->assertJsonPath('reason_code', 'DOCUMENT_NOT_DRAFT');
        $this->putJson("/api/v1/supplier-returns/{$id}", $over)->assertUnprocessable()->assertJsonPath('reason_code', 'DOCUMENT_NOT_DRAFT');
        $this->assertSame(0, RejectedStockExit::query()->count());

        // A delivery line may appear once, an empty document is refused, and the expected credit needs its currency.
        $line = $receipt->lines()->sole()->id;
        $this->postJson('/api/v1/supplier-returns', $this->returnBody($receipt, ['lines' => [['quantity' => '5'], ['goods_receipt_line_id' => $line, 'quantity' => '1']]]))->assertUnprocessable()->assertJsonPath('reason_code', 'DUPLICATE_RECEIPT_LINE');
        $this->postJson('/api/v1/supplier-returns', array_replace($this->returnBody($receipt), ['lines' => []]))->assertUnprocessable();
        $this->postJson('/api/v1/supplier-returns', $this->returnBody($receipt, ['expected_credit_amount' => '10']))->assertUnprocessable();
    }

    public function test_every_endpoint_needs_its_dedicated_capability_and_the_two_documents_stay_separate(): void
    {
        $this->grouped();
        $receipt = $this->mixedDelivery();
        $line = $receipt->lines()->sole()->id;
        $stranger = PurchasingActor::make(['inventory.package-state.view']);
        $scrapOnly = PurchasingActor::make(['inventory.scrap.create', 'inventory.scrap.post', 'inventory.package-state.view']);
        $id = $this->actingAs($this->drafter())->postJson('/api/v1/supplier-returns', $this->returnBody($receipt))->assertCreated()->json('data.id');

        foreach ([$stranger, $scrapOnly] as $user) {
            $this->actingAs($user);
            $this->getJson('/api/v1/supplier-returns')->assertForbidden();
            $this->getJson("/api/v1/supplier-returns/{$id}")->assertForbidden();
            $this->postJson('/api/v1/supplier-returns', $this->returnBody($receipt))->assertForbidden();
            $this->putJson("/api/v1/supplier-returns/{$id}", $this->returnBody($receipt))->assertForbidden();
            $this->postJson("/api/v1/supplier-returns/{$id}/post")->assertForbidden();
            $this->postJson("/api/v1/supplier-returns/{$id}/cancel")->assertForbidden();
            $this->postJson('/api/v1/supplier-returns/preview', ['lines' => [['goods_receipt_line_id' => $line]]])->assertForbidden();
        }
        $this->actingAs($stranger);
        $this->getJson('/api/v1/stock-scraps')->assertForbidden();
        $this->getJson("/api/v1/goods-receipt-lines/{$line}/rejected-stock?kind=SCRAP")->assertForbidden();
        $this->actingAs($scrapOnly);
        $this->getJson('/api/v1/stock-scraps')->assertOk();
        $this->getJson("/api/v1/goods-receipt-lines/{$line}/rejected-stock?kind=SCRAP&quantity=5")->assertOk()->assertJsonPath('data.available', '5.0000');
        $this->assertSame(0, RejectedStockExit::query()->count());
    }

    public function test_another_companys_documents_are_not_reachable_by_id(): void
    {
        $this->grouped();
        $receipt = $this->mixedDelivery();
        $body = $this->returnBody($receipt);
        $id = $this->actingAs(PurchasingActor::make(['purchasing.supplier-return.create', 'purchasing.supplier-return.post', 'inventory.package-state.view']))
            ->postJson('/api/v1/supplier-returns', $body)->assertCreated()->json('data.id');
        $other = DB::table('companies')->insertGetId(['name' => 'Other Co', 'created_at' => now(), 'updated_at' => now()]);
        $this->app->instance(CompanyContext::class, new class($other) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });

        $this->getJson("/api/v1/supplier-returns/{$id}")->assertNotFound();
        $this->putJson("/api/v1/supplier-returns/{$id}", $body)->assertNotFound();
        $this->postJson("/api/v1/supplier-returns/{$id}/post")->assertNotFound();
        $this->postJson("/api/v1/supplier-returns/{$id}/cancel")->assertNotFound();
        $this->assertSame(0, RejectedStockExit::query()->withoutGlobalScopes()->count());
    }

    public function test_a_person_limited_to_their_units_cannot_see_draft_or_post_documents_of_a_warehouse_they_do_not_own(): void
    {
        $this->grouped();
        $receipt = $this->mixedDelivery();
        $id = $this->actingAs(PurchasingActor::make(['purchasing.supplier-return.create', 'purchasing.supplier-return.post', 'inventory.package-state.view']))
            ->postJson('/api/v1/supplier-returns', $this->returnBody($receipt))->assertCreated()->json('data.id');
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        $role = Role::create(['team_id' => 1, 'name' => 'Limited returns', 'guard_name' => 'web']);
        foreach (['purchasing.supplier-return.create', 'purchasing.supplier-return.post', 'inventory.package-state.view'] as $name) {
            Permission::findOrCreate($name, 'web');
            $role->givePermissionTo($name);
        }
        $limited = User::factory()->create(['company_id' => 1]);
        $limited->assignRole($role);
        AccessScopeLimit::create(['user_id' => $limited->id, 'role_id' => $role->id, 'scope' => 'MY_UNITS']);
        $this->actingAs($limited);

        $this->getJson('/api/v1/supplier-returns')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/supplier-returns/{$id}")->assertNotFound();
        $this->putJson("/api/v1/supplier-returns/{$id}", $this->returnBody($receipt))->assertNotFound();
        $this->postJson("/api/v1/supplier-returns/{$id}/post")->assertNotFound();
        $this->postJson('/api/v1/supplier-returns', $this->returnBody($receipt))->assertUnprocessable()->assertJsonPath('reason_code', 'OUT_OF_SCOPE');
        $this->postJson('/api/v1/supplier-returns/preview', ['lines' => [['goods_receipt_line_id' => $receipt->lines()->sole()->id]]])->assertUnprocessable();
        $this->assertSame(0, RejectedStockExit::query()->count());
    }

    public function test_the_database_freezes_posted_documents_and_refuses_a_post_without_its_exits(): void
    {
        $this->grouped();
        $receipt = $this->mixedDelivery();
        $this->actingAs(PurchasingActor::make(['purchasing.supplier-return.create', 'purchasing.supplier-return.post', 'inventory.package-state.view']));
        $posted = $this->postJson('/api/v1/supplier-returns', $this->returnBody($receipt))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/supplier-returns/{$posted}/post")->assertOk();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        foreach ([
            ["UPDATE supplier_returns SET reason = 'edited' WHERE id = {$posted}", 'immutable'],
            ["UPDATE supplier_return_lines SET quantity = 1 WHERE supplier_return_id = {$posted}", 'immutable'],
            ["DELETE FROM supplier_return_lines WHERE supplier_return_id = {$posted}", 'immutable'],
            ["DELETE FROM supplier_returns WHERE id = {$posted}", 'never deleted'],
        ] as [$sql, $message]) {
            try {
                DB::transaction(fn () => DB::statement($sql));
                $this->fail("{$sql} must be refused");
            } catch (QueryException $error) {
                $this->assertStringContainsString($message, $error->getMessage());
            }
        }

        // A draft cannot be flipped to POSTED by hand: without its exit records it cannot commit, and it needs a number.
        $draft = $this->postJson('/api/v1/supplier-returns', array_replace($this->returnBody($receipt), ['reason' => 'Second draft']))->assertCreated()->json('data.id');
        DB::statement("UPDATE supplier_returns SET status = 'POSTED', number = 'FAKE-1', posted_by = created_by, posted_at = now() WHERE id = {$draft}");
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('needs its matching exit record');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_a_quantity_only_scrap_document_works_without_package_fields(): void
    {
        [$order, $item, $unit] = $this->issuedOrder(ValuationMethod::Fifo, qty: '40.0000', qaRequired: true);
        $a = $this->locationId();
        $actor = $this->purchasingActor();
        PurchasingActor::grant($actor, ['purchasing.quality-disposition.post']);
        $this->world = ['item' => $item, 'unit' => $unit, 'a' => $a, 'order' => $order, 'actor' => $actor];
        $receipt = $this->receipt('8');
        $this->dispose($receipt, 'PASS', '5');
        $this->dispose($receipt, 'SCRAP', '3');
        $line = $receipt->lines()->sole()->id;

        $this->actingAs(PurchasingActor::make(['inventory.scrap.create', 'inventory.scrap.post']));
        $preview = $this->postJson('/api/v1/stock-scraps/preview', ['lines' => [['goods_receipt_line_id' => $line, 'quantity' => '3']]])->assertOk()->json('data.0');
        $this->assertSame([false, [], null], [$preview['grouped'], $preview['blockers'], $preview['rejected_packages']]);
        $id = $this->postJson('/api/v1/stock-scraps', ['reason' => 'Cracked on delivery', 'lines' => [['goods_receipt_line_id' => $line, 'quantity' => '3']]])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/stock-scraps/{$id}/post")->assertOk()->assertJsonPath('data.status', 'POSTED');
        $this->assertSame([0, 1], [PackageOperation::query()->count(), RejectedStockExit::query()->count()]);
        $this->assertMatchesRegularExpression('/^SCR-\d+$/', $this->getJson("/api/v1/stock-scraps/{$id}")->json('data.number'));
    }
}
