<?php

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InventoryLocationModeLockingTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function modeSensitiveActions(): array
    {
        return [
            'create location' => ['app/Modules/Inventory/Actions/CreateLocation.php'],
            'update structured location fields' => ['app/Modules/Inventory/Actions/UpdateLocation.php'],
            'create warehouse location role' => ['app/Modules/Inventory/Actions/CreateWarehouseLocationAssignment.php'],
            'change inventory location mode' => ['app/Modules/Inventory/Actions/UpdateInventorySettings.php'],
        ];
    }

    #[DataProvider('modeSensitiveActions')]
    public function test_mode_sensitive_writes_serialize_on_the_company_row(string $path): void
    {
        $source = file_get_contents(base_path($path));

        $this->assertIsString($source);
        $this->assertStringContainsString('->lockForUpdate()', $source);
    }
}
