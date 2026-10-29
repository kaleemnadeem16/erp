<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Currency;
use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Actions\CreateQuotationDraft;
use App\Modules\Sales\Actions\PublishQuotationControlSet;
use App\Modules\Sales\Actions\UpdateQuotationDraft;
use App\Modules\Sales\Models\QuotationControlSet;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as Sales;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

/**
 * Phase 4 quotation issuance, as XYZ Paints: the company's published control set (a mandatory line floor and an advisory one) and one prepared quotation draft for an existing demo
 * customer with a service line, a stocked-product line and an optional line, all through the real guarded Actions. The staged duties are switched on only while seeding. The draft is
 * left unissued on purpose: until the Tax jurisdiction/classification source exists, readiness honestly reports the tax blockers. A rerun adds nothing.
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
        if (QuotationRevision::query()->exists()) {
            return;
        }
        $customer = Party::query()->where('code', 'cus-alpha')->firstOrFail();
        $service = Item::query()->where('code', 'demo-site-application')->firstOrFail();
        $paint = Item::query()->where('code', 'demo-paint-white')->firstOrFail();
        $today = now()->format('Y-m-d');
        $draft = app(CreateQuotationDraft::class)($admin, 'demo-quotation-1', [
            'recipient_party_id' => $customer->id, 'pricing_date' => $today, 'tax_estimate_date' => $today, 'valid_through_local_date' => now()->addDays(30)->format('Y-m-d'),
        ]);
        app(UpdateQuotationDraft::class)($admin, $draft->revisionId, $draft->inputGeneration, 'demo-quotation-1-lines', ['add_lines' => [
            ['source_kind' => 'SERVICE_ITEM', 'item_id' => $service->id, 'offered_quantity' => '16', 'unit_price' => '1800.0000', 'description' => 'On-site paint application, two painters'],
            ['source_kind' => 'DIRECT_ITEM', 'item_id' => $paint->id, 'offered_quantity' => '12', 'unit_price' => '9500.0000'],
            ['source_kind' => 'MANUAL', 'description' => 'Surface preparation and masking', 'manual_measure' => 'job', 'offered_quantity' => '1', 'unit_price' => '15000.0000', 'membership' => 'OPTIONAL'],
        ]]);
    }
}
