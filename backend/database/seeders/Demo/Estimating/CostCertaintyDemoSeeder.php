<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Estimating;

use App\Models\Uom;
use App\Models\User;
use App\Modules\Estimating\Actions\ActivateAssemblyVersion;
use App\Modules\Estimating\Models\Assembly;
use App\Modules\Estimating\Models\ResourceRate;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Support\CompanyContext;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/** Three resource-based certainty examples; no stock postings or existing evidence is changed. */
final class CostCertaintyDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    public function run(): void
    {
        $this->ensureDemoEnvironment();
        app(PermissionRegistrar::class)->setPermissionsTeamId(app(CompanyContext::class)->id());
        $actor = User::where('email', config('demo.user.email'))->firstOrFail();
        $hour = Uom::where('code', 'hr')->firstOrFail();
        $category = Category::firstOrCreate(['code' => 'demo-cost-evidence'], ['name' => 'Cost evidence examples', 'default_valuation_method' => 'AVCO']);
        $missing = Item::firstOrCreate(['code' => 'demo-labour-unpriced'], ['name' => 'Labour awaiting a rate', 'type' => 'LABOUR', 'stock_uom_id' => $hour->id, 'category_id' => $category->id]);
        $free = Item::firstOrCreate(['code' => 'demo-labour-free'], ['name' => 'Labour with a configured zero rate', 'type' => 'LABOUR', 'stock_uom_id' => $hour->id, 'category_id' => $category->id]);
        ResourceRate::firstOrCreate(['item_id' => $free->id, 'effective_from' => '2026-01-01'], ['rate' => '0.0000', 'rate_uom_id' => $hour->id]);
        foreach (['unknown' => $missing, 'partial' => $missing, 'zero' => $free] as $scenario => $item) {
            $assembly = Assembly::firstOrCreate(['code' => 'demo-asm-cost-'.$scenario], [
                'name' => match ($scenario) {
                    'unknown' => 'Cost unknown — awaiting labour rate', 'partial' => 'Cost incomplete — known subcontract plus unpriced labour', default => 'Cost complete — configured zero labour'
                },
                'work_uom_id' => $hour->id, 'margin_type' => 'MARKUP', 'margin_pct' => '20.0000',
            ]);
            if ($assembly->activeVersion()->exists()) {
                continue;
            }
            $version = $assembly->versions()->firstOrCreate(['version_no' => 1])->refresh();
            $version->components()->firstOrCreate(['sequence' => 0], ['kind' => 'LABOUR', 'item_id' => $item->id, 'qty_per_work_unit' => '1', 'uom_id' => $hour->id, 'sequence' => 0]);
            if ($scenario === 'partial') {
                $version->components()->firstOrCreate(['sequence' => 1], ['kind' => 'SUBCONTRACT', 'qty_per_work_unit' => '1', 'uom_id' => $hour->id, 'rate_override' => '25.0000', 'sequence' => 1]);
            }
            app(ActivateAssemblyVersion::class)($version, $actor);
        }
    }
}
