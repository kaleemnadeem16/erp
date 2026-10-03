<?php

namespace Tests\Feature\Support;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Actions\ArchiveCategory;
use App\Modules\Inventory\Actions\ArchiveItem;
use App\Modules\Inventory\Actions\ArchiveLocation;
use App\Modules\Inventory\Actions\ArchiveWarehouse;
use App\Modules\Inventory\Actions\CreateCategory;
use App\Modules\Inventory\Actions\CreateItem;
use App\Modules\Inventory\Actions\CreateLocation;
use App\Modules\Inventory\Actions\CreateWarehouse;
use App\Modules\Inventory\Actions\CreateWarehouseLocationAssignment;
use App\Modules\Inventory\Actions\DeleteWarehouseLocationAssignment;
use App\Modules\Inventory\Actions\UpdateCategory;
use App\Modules\Inventory\Actions\UpdateItem;
use App\Modules\Inventory\Actions\UpdateLocation;
use App\Modules\Inventory\Actions\UpdateWarehouse;
use App\Modules\Inventory\Actions\UpdateWarehouseLocationAssignment;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocationAssignment;
use App\Modules\Purchasing\Actions\ArchiveItemSupplier;
use App\Modules\Purchasing\Actions\CreateItemSupplier;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\UpdateItemSupplier;
use App\Modules\Purchasing\Actions\UpdatePurchaseOrder;
use App\Modules\Purchasing\Models\ItemSupplier;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

/**
 * The master-data and purchase-order Actions check their own capability, so a console command, job or integration that calls one
 * without HTTP is refused exactly as a request is (docs/architecture/access-security-audit.md, Action-level guards). The guard is
 * each Action's first statement, so it is exercised here with empty input and unsaved models: a caller without the capability is
 * refused before anything is read or written, and the happy path of every Action is covered by the suites that pass a permitted actor.
 */
class MasterDataActionGuardTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{class-string, string, \Closure(): list<mixed>}> Action => [class, capability, arguments before the actor (built lazily: models need the booted app)] */
    public static function guarded(): array
    {
        return [
            'create item' => [CreateItem::class, 'inventory.item.manage', fn () => [[]]],
            'update item' => [UpdateItem::class, 'inventory.item.manage', fn () => [new Item, []]],
            'archive item' => [ArchiveItem::class, 'inventory.item.manage', fn () => [new Item]],
            'create category' => [CreateCategory::class, 'inventory.category.manage', fn () => [[]]],
            'update category' => [UpdateCategory::class, 'inventory.category.manage', fn () => [new Category, []]],
            'archive category' => [ArchiveCategory::class, 'inventory.category.manage', fn () => [new Category]],
            'create warehouse' => [CreateWarehouse::class, 'inventory.warehouse.manage', fn () => [[]]],
            'update warehouse' => [UpdateWarehouse::class, 'inventory.warehouse.manage', fn () => [new Warehouse, []]],
            'archive warehouse' => [ArchiveWarehouse::class, 'inventory.warehouse.manage', fn () => [new Warehouse]],
            'create location' => [CreateLocation::class, 'inventory.location.manage', fn () => [[]]],
            'update location' => [UpdateLocation::class, 'inventory.location.manage', fn () => [new Location, []]],
            'archive location' => [ArchiveLocation::class, 'inventory.location.manage', fn () => [new Location]],
            'create warehouse location role' => [CreateWarehouseLocationAssignment::class, 'inventory.location.manage', fn () => [[]]],
            'update warehouse location role' => [UpdateWarehouseLocationAssignment::class, 'inventory.location.manage', fn () => [new WarehouseLocationAssignment, []]],
            'delete warehouse location role' => [DeleteWarehouseLocationAssignment::class, 'inventory.location.manage', fn () => [new WarehouseLocationAssignment]],
            'create item supplier' => [CreateItemSupplier::class, 'purchasing.item-supplier.manage', fn () => [[]]],
            'update item supplier' => [UpdateItemSupplier::class, 'purchasing.item-supplier.manage', fn () => [new ItemSupplier, []]],
            'archive item supplier' => [ArchiveItemSupplier::class, 'purchasing.item-supplier.manage', fn () => [new ItemSupplier]],
            'create purchase order' => [CreatePurchaseOrder::class, 'purchasing.purchase-order.create', fn () => [[]]],
            'update purchase order' => [UpdatePurchaseOrder::class, 'purchasing.purchase-order.create', fn () => [new PurchaseOrder, []]],
        ];
    }

    /** @param class-string $action */
    #[DataProvider('guarded')]
    public function test_a_caller_without_the_capability_is_refused_by_the_action_itself(string $action, string $capability, \Closure $arguments): void
    {
        $arguments = $arguments();
        $stranger = PermissionedActor::grant(User::factory()->create(), ['inventory.availability.view']); // holds something, but not this
        $holder = PermissionedActor::grant(User::factory()->create(), [$capability]);

        try {
            app($action)(...$arguments, ...[$stranger]);
            $this->fail("{$action} ran for a caller without {$capability}.");
        } catch (DomainInvariantException $e) {
            $this->assertStringContainsString('not permitted', $e->getMessage());
        }

        // The capability opens the guard: with it the Action goes on and fails later, on the empty input — never with "not permitted".
        try {
            app($action)(...$arguments, ...[$holder]);
        } catch (\Throwable $e) {
            $this->assertStringNotContainsString('not permitted', $e->getMessage());
        }
    }
}
