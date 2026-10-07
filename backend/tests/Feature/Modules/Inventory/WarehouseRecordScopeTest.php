<?php

namespace Tests\Feature\Modules\Inventory;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Administration\Models\OrganisationUnit;
use App\Modules\Administration\Models\OrganisationUnitResource;
use App\Modules\Inventory\Actions\CreatePhysicalCount;
use App\Modules\Inventory\Actions\ReviewNegativeStockOverride;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\NegativeStockOverrideRequest;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Support\NegativeStockReviewOutcome;
use App\Modules\Inventory\Support\ValuationMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Modules\Inventory\Concerns\BuildsOutboundRecalculation;
use Tests\TestCase;

/**
 * ADR 0089 (Q26), first slice: a person whose stock access is limited to "my units" reaches only the warehouses those units own — in
 * stock layers, the ledger and physical counts, through list, filter, search, include, show and every write — while the overview and
 * AVCO pools, which cannot be limited to a warehouse, refuse them. Checked on response bodies, with a warehouse owned by no unit and a
 * limited person who belongs to no unit.
 */
class WarehouseRecordScopeTest extends TestCase
{
    use BuildsOutboundRecalculation, RefreshDatabase;

    private const CAPS = ['inventory.stock.view', 'inventory.movement.view', 'inventory.physical_count.create', 'inventory.physical_count.count', 'inventory.physical_count.post', 'inventory.availability.view',
        'inventory.negative-stock.override', 'inventory.negative-stock.review', 'inventory.negative-stock.approve',
        'inventory.recalculation.request', 'inventory.recalculation.execute', 'inventory.cutover.create', 'inventory.cutover.stage'];

    private Location $khi;

    private Location $lhe;

    private Location $orphan;

    private User $boss;

    private User $khiClerk;

    private User $lostClerk;

    private User $bothClerk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture(ValuationMethod::Avco);
        foreach (self::CAPS as $permission) {
            Permission::findOrCreate($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Three warehouses, each with stock; the names are the codes, so a leak is easy to spot in a body.
        [$this->khi, $this->lhe, $this->orphan] = [$this->location('khi'), $this->location('lhe'), $this->location('orphan')];
        $this->receive($this->khi, '5', '10', '2026-01-01', 1);
        $this->receive($this->lhe, '7', '10', '2026-01-02', 2);
        $this->receive($this->orphan, '9', '10', '2026-01-03', 3);
        $this->transfer($this->khi, $this->lhe, '1', '2026-01-04', 4);
        $this->transfer($this->lhe, $this->orphan, '1', '2026-01-05', 5);

        $hq = $this->unit('hq');
        $khiUnit = $this->unit('khi', $hq);
        $lheUnit = $this->unit('lhe', $hq);
        OrganisationUnitResource::create(['unit_id' => $khiUnit->id, 'resource_type' => 'warehouse', 'resource_id' => $this->khi->warehouse_id]);
        OrganisationUnitResource::create(['unit_id' => $lheUnit->id, 'resource_type' => 'warehouse', 'resource_id' => $this->lhe->warehouse_id]);
        // $this->orphan's warehouse belongs to no unit.

        $this->boss = $this->person('Inventory controller', false);
        $this->khiClerk = $this->person('Karachi clerk', true, $khiUnit);
        $this->lostClerk = $this->person('Unplaced clerk', true);
        $this->bothClerk = $this->person('Two-site clerk', true, $khiUnit, $lheUnit);
    }

    private function unit(string $code, ?OrganisationUnit $parent = null): OrganisationUnit
    {
        return OrganisationUnit::create(['code' => $code, 'name' => strtoupper($code), 'parent_id' => $parent?->id, 'status' => 'ACTIVE']);
    }

    private function person(string $roleName, bool $limited, OrganisationUnit ...$units): User
    {
        $user = User::factory()->create();
        $role = Role::create(['team_id' => 1, 'name' => $roleName]);
        $role->givePermissionTo(self::CAPS);
        $user->assignRole($role);
        if ($limited) {
            AccessScopeLimit::create(['user_id' => $user->id, 'role_id' => $role->id, 'scope' => 'MY_UNITS']);
        }
        foreach ($units as $unit) {
            DB::table('organisation_unit_members')->insert(['company_id' => 1, 'unit_id' => $unit->id, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $user;
    }

    /** @return list<int> */
    private function locationIds(User $user, string $url): array
    {
        return collect($this->actingAs($user)->getJson($url)->assertOk()->json('data'))->pluck('location_id')->unique()->sort()->values()->all();
    }

    public function test_stock_layers_are_limited_to_the_warehouses_a_persons_units_own(): void
    {
        $this->assertEqualsCanonicalizing([$this->khi->id, $this->lhe->id, $this->orphan->id], $this->locationIds($this->boss, '/api/v1/stock-layers?per_page=100'));
        $this->assertSame([$this->khi->id], $this->locationIds($this->khiClerk, '/api/v1/stock-layers?per_page=100'));
        $this->assertEqualsCanonicalizing([$this->khi->id, $this->lhe->id], $this->locationIds($this->bothClerk, '/api/v1/stock-layers?per_page=100'), 'several units add up; the unowned warehouse is still out');
        $this->assertSame([], $this->locationIds($this->lostClerk, '/api/v1/stock-layers?per_page=100'), 'limited and in no unit: nothing, never everything');

        // Filters, includes and sorts cannot reach what the WHERE excludes.
        $this->assertSame([], $this->locationIds($this->khiClerk, "/api/v1/stock-layers?filter[location_id]={$this->lhe->id}"));
        $this->assertSame([], $this->locationIds($this->khiClerk, "/api/v1/stock-layers?filter[location_id]={$this->orphan->id}&include=location,item,batch&sort=received_at"));
        $body = $this->actingAs($this->khiClerk)->getJson('/api/v1/stock-layers?include=location,item,batch&per_page=100&filter[open]=true')->assertOk()->getContent();
        foreach (['lhe', 'orphan'] as $other) {
            $this->assertStringNotContainsString("\"{$other}\"", $body, "{$other} must not appear");
        }
    }

    public function test_a_layer_outside_the_scope_is_a_404_not_a_403(): void
    {
        $own = StockLayer::query()->where('location_id', $this->khi->id)->firstOrFail()->id;
        $theirs = StockLayer::query()->where('location_id', $this->lhe->id)->firstOrFail()->id;
        $unowned = StockLayer::query()->where('location_id', $this->orphan->id)->firstOrFail()->id;

        $this->actingAs($this->khiClerk)->getJson("/api/v1/stock-layers/{$own}?include=location")->assertOk();
        $this->getJson("/api/v1/stock-layers/{$theirs}")->assertNotFound();
        $this->getJson("/api/v1/stock-layers/{$unowned}")->assertNotFound();
        $this->actingAs($this->boss)->getJson("/api/v1/stock-layers/{$theirs}")->assertOk();
        $this->actingAs($this->lostClerk)->getJson("/api/v1/stock-layers/{$own}")->assertNotFound();
    }

    public function test_the_ledger_shows_a_movement_when_either_side_is_in_scope(): void
    {
        $sources = fn (User $user): array => collect($this->actingAs($user)->getJson('/api/v1/stock-moves?per_page=100')->assertOk()->json('data'))->pluck('source_id')->sort()->values()->all();

        $this->assertSame([1, 2, 3, 4, 5], $sources($this->boss));
        // Karachi: its receipt (1) and the transfer out of it (4) — not Lahore's receipt, not Lahore→orphan.
        $this->assertSame([1, 4], $sources($this->khiClerk));
        $this->assertSame([1, 2, 4, 5], $sources($this->bothClerk), 'the transfer into the unowned warehouse touches Lahore');
        $this->assertSame([], $sources($this->lostClerk));

        // The filters that name a warehouse cannot widen it.
        $byWarehouse = $this->actingAs($this->khiClerk)->getJson("/api/v1/stock-moves?filter[warehouse_id]={$this->lhe->warehouse_id}")->assertOk()->json('data.*.source_id');
        $this->assertSame([4], $byWarehouse, 'only the transfer that touches Karachi');
        $this->getJson("/api/v1/stock-moves?filter[location_id]={$this->orphan->id}")->assertOk()->assertJsonCount(0, 'data');

        $own = StockMove::query()->where('source_id', 1)->firstOrFail()->id;
        $other = StockMove::query()->where('source_id', 3)->firstOrFail()->id;
        $touching = StockMove::query()->where('source_id', 4)->firstOrFail()->id;
        $this->getJson("/api/v1/stock-moves/{$own}")->assertOk();
        $this->getJson("/api/v1/stock-moves/{$touching}?include=from_location,to_location")->assertOk();
        $this->getJson("/api/v1/stock-moves/{$other}")->assertNotFound();
    }

    public function test_physical_counts_are_listed_shown_and_changed_only_in_reached_warehouses(): void
    {
        $khiCount = $this->actingAs($this->boss)->postJson('/api/v1/physical-counts', ['warehouse_id' => $this->khi->warehouse_id])->assertCreated()->json('data.id');
        $lheCount = $this->postJson('/api/v1/physical-counts', ['warehouse_id' => $this->lhe->warehouse_id])->assertCreated()->json('data.id');
        $lheLine = $this->postJson("/api/v1/physical-counts/{$lheCount}/lines", ['item_id' => $this->item->id, 'location_id' => $this->lhe->id])->assertCreated()->json('data.id');

        $list = $this->actingAs($this->khiClerk)->getJson('/api/v1/physical-counts?include=warehouse,lines')->assertOk();
        $this->assertSame([$khiCount], $list->json('data.*.id'));
        $this->assertSame(1, $list->json('meta.total'), 'the page total follows the scope too');
        $this->assertStringNotContainsString('"lhe"', $list->getContent());
        $this->assertSame(2, $this->actingAs($this->boss)->getJson('/api/v1/physical-counts')->json('meta.total'));
        $this->assertSame(0, $this->actingAs($this->lostClerk)->getJson('/api/v1/physical-counts')->json('meta.total'));

        // Reads and every write on a count the person does not reach are a 404.
        $this->actingAs($this->khiClerk)->getJson("/api/v1/physical-counts/{$khiCount}")->assertOk();
        $this->getJson("/api/v1/physical-counts/{$lheCount}")->assertNotFound();
        $this->postJson("/api/v1/physical-counts/{$lheCount}/lines", ['item_id' => $this->item->id, 'location_id' => $this->lhe->id])->assertNotFound();
        $this->postJson("/api/v1/physical-count-lines/{$lheLine}/count", ['counted_qty' => '1.0000'])->assertNotFound();
        $this->postJson("/api/v1/physical-counts/{$lheCount}/post", ['found_costs' => []])->assertNotFound();
        $this->postJson("/api/v1/physical-counts/{$lheCount}/cancel")->assertNotFound();
        // Creating a count in a warehouse outside the scope is refused; inside it works.
        $this->postJson('/api/v1/physical-counts', ['warehouse_id' => $this->lhe->warehouse_id])->assertForbidden();
        $this->postJson('/api/v1/physical-counts', ['warehouse_id' => $this->orphan->warehouse_id])->assertForbidden();
        $this->postJson('/api/v1/physical-counts', ['warehouse_id' => $this->khi->warehouse_id])->assertCreated();
        $this->assertSame('DRAFT', DB::table('physical_counts')->where('id', $lheCount)->value('status'), 'nothing happened to the count they could not reach');
    }

    public function test_a_console_command_or_job_is_stopped_as_a_request_is(): void
    {
        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('not permitted to create a physical count in this warehouse');

        app(CreatePhysicalCount::class)(['warehouse_id' => $this->lhe->warehouse_id], $this->khiClerk);
    }

    /** @return array<string, mixed> */
    private function overrideInput(Location $location, int $sourceId): array
    {
        return [
            'item_id' => $this->item->id, 'location_id' => $location->id, 'movement_class' => 'MATERIAL_CONSUMPTION',
            'requested_qty' => '500.0000', 'source_type' => 'TEST_ISSUE', 'source_id' => $sourceId,
            'reason_code' => 'PAPERWORK_DELAY', 'explanation' => 'Receipt paperwork is delayed',
        ];
    }

    public function test_negative_stock_override_requests_follow_the_warehouse_of_their_location(): void
    {
        Company::query()->findOrFail(1)->update([
            'negative_stock_mode' => 'AUTHORISED_OVERRIDE', 'negative_stock_authorisation_mode' => 'IMMEDIATE_WITH_REVIEW',
            'negative_stock_material_consumption_enabled' => true,
        ]);
        $khiRequest = $this->actingAs($this->boss)->postJson('/api/v1/negative-stock-override-requests', $this->overrideInput($this->khi, 101))->assertCreated()->json('data.id');
        $lheRequest = $this->postJson('/api/v1/negative-stock-override-requests', $this->overrideInput($this->lhe, 102))->assertCreated()->json('data.id');

        // The list, its total and the includes follow the scope; a person in no unit sees nothing.
        $list = $this->actingAs($this->khiClerk)->getJson('/api/v1/negative-stock-override-requests?include=location')->assertOk();
        $this->assertSame([$khiRequest], $list->json('data.*.id'));
        $this->assertSame(1, $list->json('meta.total'));
        $this->assertStringNotContainsString('"lhe"', $list->getContent());
        $this->assertSame(2, $this->actingAs($this->boss)->getJson('/api/v1/negative-stock-override-requests')->json('meta.total'));
        $this->assertSame(0, $this->actingAs($this->lostClerk)->getJson('/api/v1/negative-stock-override-requests')->json('meta.total'));

        // A request elsewhere is a 404 to read and to review; raising one there is refused, in the person's own warehouse it works.
        $this->actingAs($this->khiClerk)->getJson("/api/v1/negative-stock-override-requests/{$khiRequest}")->assertOk();
        $this->getJson("/api/v1/negative-stock-override-requests/{$lheRequest}")->assertNotFound();
        $this->postJson("/api/v1/negative-stock-override-requests/{$lheRequest}/review", ['outcome' => 'JUSTIFIED', 'notes' => 'ok'])->assertNotFound();
        $this->postJson('/api/v1/negative-stock-override-requests', $this->overrideInput($this->lhe, 103))->assertForbidden();
        $this->postJson('/api/v1/negative-stock-override-requests', $this->overrideInput($this->orphan, 104))->assertForbidden();
        $this->postJson('/api/v1/negative-stock-override-requests', $this->overrideInput($this->khi, 105))->assertCreated();
        $this->assertSame(0, DB::table('negative_stock_override_reviews')->where('request_id', $lheRequest)->count(), 'nothing happened to the request they could not reach');

        $this->postJson("/api/v1/negative-stock-override-requests/{$khiRequest}/review", ['outcome' => 'JUSTIFIED', 'notes' => 'checked'])->assertOk();
        $this->actingAs($this->boss)->getJson("/api/v1/negative-stock-override-requests/{$lheRequest}")->assertOk();
    }

    public function test_a_console_command_or_job_cannot_decide_a_negative_stock_request_outside_the_scope(): void
    {
        Company::query()->findOrFail(1)->update([
            'negative_stock_mode' => 'AUTHORISED_OVERRIDE', 'negative_stock_authorisation_mode' => 'IMMEDIATE_WITH_REVIEW',
            'negative_stock_material_consumption_enabled' => true,
        ]);
        $this->actingAs($this->boss)->postJson('/api/v1/negative-stock-override-requests', $this->overrideInput($this->lhe, 201))->assertCreated();
        $request = NegativeStockOverrideRequest::query()->sole();

        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('not permitted to review a negative-stock override in this warehouse');

        app(ReviewNegativeStockOverride::class)($request, NegativeStockReviewOutcome::Justified, 'ok', $this->khiClerk);
    }

    public function test_cutover_and_recalculation_are_company_wide_so_a_limited_person_is_refused(): void
    {
        $surfaces = ['/api/v1/inventory-cutovers', '/api/v1/recalculation-requests'];

        foreach ([$this->khiClerk, $this->lostClerk, $this->bothClerk] as $limited) {
            foreach ($surfaces as $url) {
                $this->actingAs($limited)->getJson($url)->assertForbidden();
            }
            $this->postJson('/api/v1/inventory-cutovers', [])->assertForbidden();
            $this->postJson('/api/v1/recalculation-requests', [])->assertForbidden();
        }
        foreach ($surfaces as $url) {
            $this->actingAs($this->boss)->getJson($url)->assertOk();
        }
    }

    public function test_the_overview_and_avco_pools_refuse_a_limited_person_because_they_cannot_be_limited_to_a_warehouse(): void
    {
        $pool = (int) DB::table('avco_pools')->value('id');
        $surfaces = ['/api/v1/inventory-overview', '/api/v1/inventory-overview/summary', '/api/v1/avco-pools', "/api/v1/avco-pools/{$pool}"];

        foreach ([$this->khiClerk, $this->lostClerk, $this->bothClerk] as $limited) {
            foreach ($surfaces as $url) {
                $this->actingAs($limited)->getJson($url)->assertForbidden();
            }
        }
        foreach ($surfaces as $url) {
            $this->actingAs($this->boss)->getJson($url)->assertOk();
        }
    }

    public function test_scope_is_applied_at_the_api_so_internal_workflows_still_read_every_layer(): void
    {
        // Costing and posting read stock layers on behalf of whoever triggered them. A global model scope would hand a limited clerk's own
        // receipt a filtered view of the layers it prices against; so the model itself must stay unfiltered for a signed-in limited user.
        $this->actingAs($this->khiClerk);

        $this->assertSame(StockLayer::query()->count(), DB::table('stock_layers')->count());
        $this->assertSame(5, StockMove::query()->count());
    }

    public function test_a_person_with_no_limit_is_unchanged_and_one_with_no_capability_is_still_refused(): void
    {
        $nobody = User::factory()->create();
        $this->actingAs($nobody)->getJson('/api/v1/stock-layers')->assertForbidden();
        $this->actingAs($this->boss)->getJson('/api/v1/stock-layers?per_page=100')->assertOk()->assertJsonCount(StockLayer::query()->count(), 'data');
    }
}
