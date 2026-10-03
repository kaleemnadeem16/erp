<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Uom;
use App\Models\User;
use App\Modules\Inventory\Actions\CreateCategory;
use App\Modules\Inventory\Actions\CreateItem;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;

final class PackageTrackingPolicyDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    private User $actor;

    public function run(): void
    {
        $this->ensureDemoEnvironment();
        $this->actor = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        $company = $this->category('demo-package-policy-company', 'Package policy — company fallback');
        $root = $this->category('demo-package-policy-root', 'Package policy — quantity-only category default', mode: 'QUANTITY_ONLY');
        $leaf = $this->category('demo-package-policy-leaf', 'Package policy — inherits nearest ancestor', $root->id);
        $unit = Uom::query()->where('code', 'l')->firstOrFail();
        foreach ([
            ['demo-package-policy-company-item', 'Package policy — company choice', $company, null],
            ['demo-package-policy-category-item', 'Package policy — nearest category choice', $leaf, null],
            ['demo-package-policy-explicit-item', 'Package policy — explicit item choice', $leaf, 'QUANTITY_ONLY'],
        ] as [$code, $name, $category, $mode]) {
            if (Item::withTrashed()->where('code', $code)->exists()) {
                continue; // Preserve saved choices, archival and manual progress.
            }
            $attributes = [
                'code' => $code, 'name' => $name, 'type' => 'RAW',
                'description' => 'Policy resolution demo only. Quantity-only is pinned at creation; physical tracking activation is unavailable.',
                'stock_uom_id' => $unit->id, 'category_id' => $category->id,
                'is_stocked' => false, 'is_purchasable' => false, 'is_sellable' => false,
            ];
            if ($mode !== null) {
                $attributes['package_tracking_mode'] = $mode;
            }
            app(CreateItem::class)($attributes, $this->actor);
        }
    }

    private function category(string $code, string $name, ?int $parentId = null, ?string $mode = null): Category
    {
        return Category::query()->where('code', $code)->first()
            ?? app(CreateCategory::class)([
                'code' => $code, 'name' => $name, 'parent_id' => $parentId,
                'default_valuation_method' => 'FIFO', 'default_package_tracking_mode' => $mode,
            ], $this->actor);
    }
}
