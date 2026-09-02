<?php

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ControllerActionBoundaryTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function phaseOneCrudControllers(): array
    {
        return [
            'categories' => ['app/Modules/Inventory/Http/Controllers/Api/V1/CategoryController.php'],
            'items' => ['app/Modules/Inventory/Http/Controllers/Api/V1/ItemController.php'],
            'parties' => ['app/Modules/Parties/Http/Controllers/Api/V1/PartyController.php'],
            'customer accounts' => ['app/Modules/Parties/Http/Controllers/Api/V1/CustomerAccountController.php'],
            'supplier accounts' => ['app/Modules/Parties/Http/Controllers/Api/V1/SupplierAccountController.php'],
            'chart of accounts' => ['app/Modules/Accounting/Http/Controllers/Api/V1/ChartOfAccountController.php'],
            'item suppliers' => ['app/Modules/Purchasing/Http/Controllers/Api/V1/ItemSupplierController.php'],
            'warehouses' => ['app/Modules/Inventory/Http/Controllers/Api/V1/WarehouseController.php'],
            'locations' => ['app/Modules/Inventory/Http/Controllers/Api/V1/LocationController.php'],
            'warehouse location roles' => ['app/Modules/Inventory/Http/Controllers/Api/V1/WarehouseLocationAssignmentController.php'],
            'inventory settings' => ['app/Modules/Inventory/Http/Controllers/Api/V1/InventorySettingsController.php'],
            'purchase orders' => ['app/Modules/Purchasing/Http/Controllers/Api/V1/PurchaseOrderController.php'],
            'goods receipts' => ['app/Modules/Purchasing/Http/Controllers/Api/V1/GoodsReceiptController.php'],
            'quality dispositions' => ['app/Modules/Purchasing/Http/Controllers/Api/V1/QualityDispositionController.php'],
            'purchasing settings' => ['app/Modules/Purchasing/Http/Controllers/Api/V1/PurchasingSettingsController.php'],
        ];
    }

    #[DataProvider('phaseOneCrudControllers')]
    public function test_writes_are_delegated_to_actions(string $path): void
    {
        $source = file_get_contents(base_path($path));

        $this->assertIsString($source);
        $this->assertDoesNotMatchRegularExpression('/::create\(|->(?:update|delete)\(/', $source);
    }
}
