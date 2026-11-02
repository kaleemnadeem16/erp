<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Currency;
use App\Models\User;
use App\Modules\Sales\Actions\PublishQuotationControlSet;
use App\Modules\Sales\Models\QuotationControlSet;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as Sales;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

/**
 * Phase 4 quotation issuance, as XYZ Paints: the company's published control set (a mandatory line floor and an advisory one), through the real guarded Action, and the demo administrator's
 * quotation duties. The staged duties are switched on only while seeding. The named quotation drafts are `QuotationDraftsDemoSeeder`'s; until the Tax jurisdiction/classification source
 * exists none of them can be issued, and readiness honestly reports the tax blockers. A rerun adds nothing.
 */
final class QuotationDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    public function run(): void
    {
        $this->ensureDemoEnvironment();
        $admin = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        foreach ([Sales::QUOTATION_VIEW, Sales::QUOTATION_PREPARE, Sales::QUOTATION_ISSUE, Sales::FINANCIAL_CONFIGURE] as $duty) {
            Permission::findOrCreate($duty, 'web');
        }
        // Unrestricted administrator authority: the administrator holds every quotation duty (staged duties are still refused to everyone in a deployed environment).
        $admin->givePermissionTo([Sales::QUOTATION_VIEW, Sales::QUOTATION_PREPARE, Sales::QUOTATION_ISSUE, Sales::FINANCIAL_CONFIGURE]);

        $shipped = app(SalesDutyManifest::class);
        app()->instance(SalesDutyManifest::class, $shipped->withActivated([Sales::QUOTATION_VIEW, Sales::QUOTATION_PREPARE, Sales::QUOTATION_ISSUE, Sales::FINANCIAL_CONFIGURE]));
        try {
            DB::transaction(fn () => $this->seed($admin));
        } finally {
            app()->instance(SalesDutyManifest::class, $shipped);
        }
    }

    private function seed(User $admin): void
    {
        $pkr = Currency::query()->where('code', 'PKR')->firstOrFail();
        if (! QuotationControlSet::query()->exists()) {
            app(PublishQuotationControlSet::class)($admin, [
                ['ref' => 'line-floor', 'kind' => 'FLOOR', 'mode' => 'MANDATORY', 'limit' => '1000', 'currency_id' => $pkr->id],
                ['ref' => 'line-floor-advice', 'kind' => 'FLOOR', 'mode' => 'ADVISORY', 'limit' => '5000', 'currency_id' => $pkr->id],
            ], 'Demo: no line below Rs 1,000; advise review below Rs 5,000');
        }
    }
}
