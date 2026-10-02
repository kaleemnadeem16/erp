<?php

namespace Tests\Feature\Modules\Numbering;

use App\Models\User;
use App\Modules\Numbering\Models\NumberingCounter;
use App\Modules\Numbering\Models\NumberingSeries;
use App\Modules\Numbering\Services\NumberingService;
use App\Modules\Numbering\Support\NumberingRegistry;
use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeNumberableDocument;
use Tests\Support\PurchasingActor;
use Tests\TestCase;

/** Settings → Document Numbering through the API: who may read and change, what each call returns, and that the tester never consumes. */
class NumberingApiTest extends TestCase
{
    use RefreshDatabase;

    /** @param  string|null  $guard */
    protected function actingAsUnprivileged(UserContract $user, $guard = null): static
    {
        return parent::actingAs($user, $guard);
    }

    private User $viewer;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        app(NumberingRegistry::class)->register(new FakeNumberableDocument);
        $this->viewer = PurchasingActor::make(['numbering.view']);
        $this->manager = PurchasingActor::make(['numbering.view', 'sales.numbering.manage', 'purchasing.numbering.manage', 'numbering.constant.manage', 'numbering.counter.raise']);
    }

    private function as(User $user): static
    {
        return $this->actingAsUnprivileged($user);
    }

    private function body(string $template = 'INV-{SEQ:5}', string $period = 'NONE', array $dimensions = [], string $continuity = 'ALLOW_GAPS'): array
    {
        return ['template' => $template, 'scope_period' => $period, 'scope_dimensions' => $dimensions, 'continuity' => $continuity, 'reason' => 'test'];
    }

    public function test_the_overview_lists_every_registered_type_with_its_series_and_a_live_example(): void
    {
        $response = $this->as($this->viewer)->getJson('/api/v1/numbering/document-types')->assertOk();

        $types = collect($response->json('data'))->keyBy('key');
        $this->assertSame(['GOODS_RECEIPT', 'PURCHASE_ORDER', 'SALES_INVOICE'], $types->keys()->sort()->values()->all());
        $po = $types['PURCHASE_ORDER'];
        $this->assertSame('Purchase order', $po['label']);
        $this->assertSame('when it is issued', $po['official_when']);
        $this->assertFalse($po['can_manage'], 'a viewer may look, not change');
        $this->assertSame('DEFAULT', $po['series'][0]['code']);
        $this->assertSame('{SEQ:4}', $po['series'][0]['version']['template']);
        $this->assertSame('0001', $po['series'][0]['example']);
        $this->assertSame(['SUPPLIER_CODE', 'CURRENCY'], array_column($po['variables'], 'key'));
        $this->assertSame(['WAREHOUSE'], $types['GOODS_RECEIPT']['dimensions']);
    }

    public function test_reading_needs_the_numbering_view_permission(): void
    {
        $this->getJson('/api/v1/numbering/document-types')->assertUnauthorized();
        $nobody = PurchasingActor::make(['purchasing.purchase-order.view']);

        foreach (['document-types', 'context', 'constants'] as $path) {
            $this->as($nobody)->getJson("/api/v1/numbering/{$path}")->assertForbidden();
        }
        $this->as($nobody)->postJson('/api/v1/numbering/preview', $this->body() + ['document_type' => 'SALES_INVOICE'])->assertForbidden();
    }

    public function test_the_context_names_the_time_zone_and_whether_it_is_still_the_default(): void
    {
        $this->as($this->viewer)->getJson('/api/v1/numbering/context')->assertOk()
            ->assertJsonPath('data.timezone', 'UTC')->assertJsonPath('data.timezone_is_default', true)
            ->assertJsonPath('data.engine_variables.0.key', 'YYYY');

        DB::table('companies')->where('id', 1)->update(['accounting_timezone' => 'Asia/Karachi']);
        $this->as($this->viewer)->getJson('/api/v1/numbering/context')->assertJsonPath('data.timezone_is_default', false);
    }

    public function test_the_tester_explains_a_configuration_and_consumes_nothing(): void
    {
        DB::table('companies')->where('id', 1)->update(['accounting_timezone' => 'Asia/Karachi']);
        $this->as($this->manager)->postJson('/api/v1/numbering/series', ['document_type' => 'SALES_INVOICE', 'code' => 'EXPORT', 'name' => 'Export', 'is_selectable' => true] + $this->body('EXP-{YYYY}-{SEQ:5}', 'YEAR'))->assertCreated();
        $before = DB::table('numbering_counters')->get();

        $preview = $this->as($this->viewer)->postJson('/api/v1/numbering/preview', ['document_type' => 'SALES_INVOICE', 'series_code' => 'EXPORT', 'at' => '2026-10-02T10:00:00Z'] + $this->body('EXP-{YYYY}-{SEQ:5}', 'YEAR'))->assertOk();

        $preview->assertJsonPath('data.example', 'EXP-2026-00001')->assertJsonPath('data.timezone', 'Asia/Karachi')
            ->assertJsonPath('data.counter_scope', 'Company + Year')->assertJsonPath('data.first_of_next_period', 'EXP-2027-00001')
            ->assertJsonPath('data.errors', []);
        $this->assertEquals($before, DB::table('numbering_counters')->get());
    }

    public function test_the_tester_reports_a_bad_format_as_data_not_as_a_failed_request(): void
    {
        $this->as($this->viewer)->postJson('/api/v1/numbering/preview', ['document_type' => 'SALES_INVOICE'] + $this->body('INV-{NOPE}-{SEQ:5}'))
            ->assertOk()->assertJsonPath('data.example', null)->assertJsonPath('data.errors.0', fn (string $m) => str_contains($m, '{NOPE} is not available'));
    }

    public function test_a_manager_adds_a_governed_version_and_the_change_is_visible_in_the_history(): void
    {
        $series = NumberingSeries::query()->where('doc_type', 'PURCHASE_ORDER')->first()
            ?? $this->firstSeriesFor('PURCHASE_ORDER');

        $response = $this->as($this->manager)->postJson("/api/v1/numbering/series/{$series->id}/versions", $this->body('PO-{SEQ:5}'))->assertCreated();
        $response->assertJsonPath('data.version.template', 'PO-{SEQ:5}')->assertJsonPath('data.example', 'PO-00001');

        $history = $this->as($this->viewer)->getJson("/api/v1/numbering/series/{$series->id}/versions")->assertOk()->json('data');
        $this->assertSame(['PO-{SEQ:5}', '{SEQ:4}'], array_column($history, 'template'));
        $this->assertSame(['ACTIVE', 'SUPERSEDED'], array_column($history, 'status'));
        $this->assertSame($this->manager->name, $history[0]['changed_by_name']);
    }

    private function firstSeriesFor(string $type): NumberingSeries
    {
        $this->as($this->viewer)->getJson('/api/v1/numbering/document-types')->assertOk();

        return NumberingSeries::query()->where('doc_type', $type)->firstOrFail();
    }

    public function test_changing_a_type_needs_the_permission_that_type_registers(): void
    {
        $series = $this->firstSeriesFor('PURCHASE_ORDER');
        $salesOnly = PurchasingActor::make(['numbering.view', 'sales.numbering.manage']);

        $this->as($this->viewer)->postJson("/api/v1/numbering/series/{$series->id}/versions", $this->body('PO-{SEQ:5}'))->assertForbidden();
        $this->as($salesOnly)->postJson("/api/v1/numbering/series/{$series->id}/versions", $this->body('PO-{SEQ:5}'))->assertForbidden();
        $this->as($salesOnly)->postJson('/api/v1/numbering/series', ['document_type' => 'PURCHASE_ORDER', 'code' => 'X', 'name' => 'X', 'is_selectable' => false] + $this->body())->assertForbidden();
        $this->as($salesOnly)->postJson('/api/v1/numbering/series', ['document_type' => 'NOPE', 'code' => 'X', 'name' => 'X', 'is_selectable' => false] + $this->body())->assertForbidden();
    }

    public function test_a_format_the_framework_refuses_comes_back_as_a_422_with_the_reason(): void
    {
        $series = $this->firstSeriesFor('PURCHASE_ORDER');

        $this->as($this->manager)->postJson("/api/v1/numbering/series/{$series->id}/versions", $this->body('PO-{NOPE}-{SEQ:5}'))
            ->assertUnprocessable()->assertJsonPath('message', fn (string $m) => str_contains($m, 'is not available for Purchase order numbers'));
        $this->as($this->manager)->postJson("/api/v1/numbering/series/{$series->id}/versions", ['reason' => ''] + $this->body())->assertUnprocessable();
    }

    public function test_a_second_series_and_a_routing_rule_through_the_api(): void
    {
        $created = $this->as($this->manager)->postJson('/api/v1/numbering/series', ['document_type' => 'SALES_INVOICE', 'code' => 'EXPORT', 'name' => 'Export', 'is_selectable' => false] + $this->body('EXP-{SEQ:5}'))
            ->assertCreated()->assertJsonPath('data.code', 'EXPORT')->assertJsonPath('data.is_default', false);
        $id = $created->json('data.id');

        $rule = [['attribute' => 'SALE_TYPE', 'operator' => 'EQ', 'values' => ['EXPORT']]];
        $this->as($this->manager)->postJson("/api/v1/numbering/series/{$id}/rules", ['priority' => 10, 'conditions' => $rule])->assertCreated();
        $this->as($this->viewer)->getJson("/api/v1/numbering/series/{$id}/rules")->assertOk()->assertJsonPath('data.0.conditions.0.attribute', 'SALE_TYPE');
        $this->as($this->manager)->postJson("/api/v1/numbering/series/{$id}/rules", ['priority' => 5, 'conditions' => [['attribute' => 'COLOUR', 'operator' => 'EQ', 'values' => ['RED']]]])->assertUnprocessable();

        // The rule is live: an export invoice is numbered from the new series.
        $number = app(NumberingService::class)->allocate(FakeNumberableDocument::context('2026-10-02 10:00:00', 'LHR', ['SALE_TYPE' => 'EXPORT']))->number;
        $this->assertSame('EXP-00001', $number);
    }

    public function test_counters_are_listed_and_raised_with_a_reason_and_never_lowered(): void
    {
        $series = $this->firstSeriesFor('SALES_INVOICE');
        app(NumberingService::class)->allocate(FakeNumberableDocument::context('2026-10-02 10:00:00'));
        $counter = NumberingCounter::query()->where('series_id', $series->id)->firstOrFail();

        $list = $this->as($this->viewer)->getJson("/api/v1/numbering/series/{$series->id}/counters")->assertOk()->json('data');
        $this->assertSame(['ALL'], array_column($list, 'counter_key'));
        $this->assertSame(2, $list[0]['next_number']);

        $this->as($this->viewer)->postJson("/api/v1/numbering/counters/{$counter->id}/raise", ['next' => 500, 'reason' => 'legacy'])->assertForbidden();
        $this->as($this->manager)->postJson("/api/v1/numbering/counters/{$counter->id}/raise", ['next' => 500, 'reason' => 'legacy up to 499'])
            ->assertOk()->assertJsonPath('data.next_number', 500)->assertJsonPath('data.from_value', 2);
        $this->as($this->manager)->postJson("/api/v1/numbering/counters/{$counter->id}/raise", ['next' => 10, 'reason' => 'oops'])
            ->assertUnprocessable()->assertJsonPath('message', fn (string $m) => str_contains($m, 'can only be raised'));
        $this->as($this->manager)->postJson("/api/v1/numbering/counters/{$counter->id}/raise", ['next' => 600, 'reason' => ''])->assertUnprocessable();
    }

    public function test_company_constants_are_created_changed_and_listed_as_history(): void
    {
        $this->as($this->viewer)->postJson('/api/v1/numbering/constants', ['code' => 'REGION', 'value' => 'NORTH', 'reason' => 'x'])->assertForbidden();

        $this->as($this->manager)->postJson('/api/v1/numbering/constants', ['code' => 'REGION', 'value' => 'NORTH', 'reason' => 'launch'])->assertCreated()->assertJsonPath('data.status', 'ACTIVE');
        $this->as($this->manager)->postJson('/api/v1/numbering/constants', ['code' => 'REGION', 'value' => 'SOUTH', 'reason' => 'restructure'])->assertCreated();
        $this->as($this->manager)->postJson('/api/v1/numbering/constants', ['code' => 'YYYY', 'value' => 'X', 'reason' => 'r'])->assertUnprocessable();

        $rows = $this->as($this->viewer)->getJson('/api/v1/numbering/constants')->assertOk()->json('data');
        $this->assertSame(['SOUTH', 'NORTH'], array_column($rows, 'value'));
        $this->assertSame(['ACTIVE', 'SUPERSEDED'], array_column($rows, 'status'));
        $this->as($this->viewer)->getJson('/api/v1/numbering/context')->assertJsonPath('data.constants.0', ['code' => 'REGION', 'value' => 'SOUTH']);
    }

    public function test_listing_is_scoped_to_the_current_company(): void
    {
        $this->firstSeriesFor('PURCHASE_ORDER');
        DB::table('companies')->insert(['id' => 2, 'name' => 'Other', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('numbering_series')->insert(['company_id' => 2, 'doc_type' => 'PURCHASE_ORDER', 'code' => 'OTHERS', 'name' => 'Not ours', 'is_default' => false, 'is_selectable' => false, 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);

        $codes = collect($this->as($this->viewer)->getJson('/api/v1/numbering/document-types')->json('data'))->firstWhere('key', 'PURCHASE_ORDER')['series'];
        $this->assertSame(['DEFAULT'], array_column($codes, 'code'));
    }
}
