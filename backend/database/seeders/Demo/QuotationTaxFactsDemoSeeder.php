<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Support\CompanyContext;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Fictitious demo facts only: never infer or install a real jurisdiction or tax rate. */
final class QuotationTaxFactsDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    public function run(): void
    {
        $this->ensureDemoEnvironment();
        $company = app(CompanyContext::class)->id();
        $admin = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        $item = Item::query()->where('code', 'demo-site-application')->firstOrFail();
        if (! DB::table('tax_quotation_jurisdictions')->where('company_id', $company)->exists()) {
            DB::table('tax_quotation_jurisdictions')->insert([
                'company_id' => $company, 'jurisdiction' => 'demo-illustrative', 'effective_from' => '2026-01-01',
                'created_by' => $admin->id, 'reason' => 'Fictitious demo only; not a legal tax declaration.',
            ]);
        }
        if (! DB::table('tax_quotation_item_classifications')->where('company_id', $company)->where('item_id', $item->id)->exists()) {
            DB::table('tax_quotation_item_classifications')->insert([
                'company_id' => $company, 'item_id' => $item->id, 'nature' => 'SERVICES', 'classification' => 'demo-service',
                'effective_from' => '2026-01-01', 'created_by' => $admin->id, 'reason' => 'Fictitious demo only; not a legal tax declaration.',
            ]);
        }
    }
}
