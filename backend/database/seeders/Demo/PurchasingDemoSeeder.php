<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Uom;
use App\Modules\Inventory\Models\Item;
use App\Modules\Parties\Models\SupplierAccount;
use App\Modules\Purchasing\Models\ItemSupplier;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;

final class PurchasingDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    public function run(): void
    {
        $this->ensureDemoEnvironment();

        $kg = Uom::query()->where('code', 'kg')->firstOrFail();
        $litre = Uom::query()->where('code', 'l')->firstOrFail();
        $each = Uom::query()->where('code', 'ea')->firstOrFail();

        $relationships = [
            ['item' => 'demo-pig-blue', 'supplier' => 'sup-punjab', 'supplier_code' => 'PB-25KG', 'priority' => 1, 'lead' => 4, 'moq' => '25.0000', 'uom' => $kg],
            ['item' => 'demo-pig-blue', 'supplier' => 'sup-pakchem', 'supplier_code' => 'BLUE-PHT', 'priority' => 2, 'lead' => 7, 'moq' => '50.0000', 'uom' => $kg],
            ['item' => 'demo-pig-red', 'supplier' => 'sup-punjab', 'supplier_code' => 'IROX-RD', 'priority' => 1, 'lead' => 5, 'moq' => '25.0000', 'uom' => $kg],
            ['item' => 'demo-thinner', 'supplier' => 'sup-pakchem', 'supplier_code' => 'THN-200', 'priority' => 1, 'lead' => 6, 'moq' => '200.0000', 'uom' => $litre],
            ['item' => 'demo-spray-unit', 'supplier' => 'sup-coatings', 'supplier_code' => 'ASU-450', 'priority' => 1, 'lead' => 12, 'moq' => '1.0000', 'uom' => $each],
        ];

        foreach ($relationships as $data) {
            $item = Item::query()->where('code', $data['item'])->firstOrFail();
            $supplier = SupplierAccount::query()->where('code', $data['supplier'])->firstOrFail();

            ItemSupplier::query()->updateOrCreate(
                ['item_id' => $item->getKey(), 'supplier_account_id' => $supplier->getKey()],
                [
                    'supplier_item_code' => $data['supplier_code'],
                    'priority' => $data['priority'],
                    'lead_time_days' => $data['lead'],
                    'minimum_order_qty' => $data['moq'],
                    'purchase_uom_id' => $data['uom']->getKey(),
                    'active' => true,
                ],
            );
        }
    }
}
