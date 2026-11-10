<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Currency;
use App\Models\User;
use App\Modules\Company\Actions\SaveCompanyBranding;
use App\Modules\Company\Models\CompanyBrandVersion;
use App\Modules\Company\Support\CompanyDutyManifest;
use App\Modules\Inventory\Models\Item;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Actions\CorrectManualQuotationDelivery;
use App\Modules\Sales\Actions\CreateQuotationDraft;
use App\Modules\Sales\Actions\IssueQuotation;
use App\Modules\Sales\Actions\RecordManualQuotationDelivery;
use App\Modules\Sales\Actions\RequestQuotationDocument;
use App\Modules\Sales\Actions\UpdateQuotationDraft;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Authority\SalesDevPreview;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Support\CompanyContext;
use App\Support\Demo\DemoQuotationReplay;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Throwable;

/**
 * Demo data for the quotation document feature: one issued quotation with its generated official PDF, a recorded manual delivery and the correction journey (a delivery
 * recorded to the wrong recipient and corrected with a reason, and a delivery withdrawn because it never happened), created once through the real Actions
 * (every key is fixed, so a rerun adds nothing; create-only, nothing is ever edited or deleted). The company is a parameter of `seedFor`; `run()` uses the current one. It runs ONLY on a developer's machine with the local quotation preview switched on (SALES_DEV_QUOTATION_DUTIES) and the
 * named demo login holding the preview duties and the issue duty; otherwise it says why it skipped and writes nothing. It never grants anything and never fakes tax or
 * control evidence: if the real issue path refuses (for example no tax context is configured yet), the refusal is shown and nothing is half-made.
 */
final class QuotationDocumentsDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    /** What the demo login must hold: ordinary quotation work, the issue duty and the document and delivery duties (all granted by `sales:dev-grant-quotation`). */
    private const DUTIES = [
        PermissionVocabulary::QUOTATION_VIEW, PermissionVocabulary::QUOTATION_PREPARE, PermissionVocabulary::QUOTATION_ISSUE,
        PermissionVocabulary::DOCUMENT_GENERATE, PermissionVocabulary::DOCUMENT_VIEW, PermissionVocabulary::DELIVERY_VIEW, PermissionVocabulary::DELIVERY_RECORD,
    ];

    public function run(): void
    {
        $this->seedFor(app(CompanyContext::class)->id());
    }

    /** Builds the demo documents for one company; refuses any company other than the current one, because the Actions work in the current company only. */
    public function seedFor(int $companyId): void
    {
        $this->ensureDemoEnvironment();
        if ($companyId !== app(CompanyContext::class)->id()) {
            $this->say('Quotation documents demo skipped: the requested company is not the current company.');

            return;
        }
        if (! SalesDevPreview::enabled()) {
            $this->say('Quotation documents demo skipped: set SALES_DEV_QUOTATION_DUTIES=true in a local environment (and run sales:dev-grant-quotation for the demo login) to build it.');

            return;
        }
        // The named demo login, exactly as the other quotation demo seeders do: it is the login `sales:dev-grant-quotation` grants the preview duties to. "The first
        // user" is not it once other demo logins exist, and that login has no quotation duties or record access.
        $maker = User::query()->where('company_id', $companyId)->where('email', strtolower((string) config('demo.user.email')))->first();
        $customer = Party::query()->where('company_id', $companyId)->where('code', 'cus-alpha')->first();
        $service = Item::query()->where('company_id', $companyId)->where('code', 'demo-site-application')->first();
        $pkr = Currency::query()->where('code', 'PKR')->first();
        if ($maker === null || $customer === null || $service === null || $pkr === null) {
            $this->say('Quotation documents demo skipped: the demo login, customer or service item does not exist yet (run the demo seed first).');

            return;
        }
        if (! DB::table('tax_quotation_declaration_versions')->where('company_id', $companyId)->where('code', 'demo-illustrative')->exists()) {
            $this->say('Quotation documents demo skipped: the fictitious demo tax declaration does not exist yet (QuotationIssuingDemoSeeder builds it; this seeder runs after it).');

            return;
        }
        $missing = array_values(array_filter(self::DUTIES, fn (string $duty): bool => ! $maker->can($duty)));
        if ($missing !== []) {
            $this->say('Quotation documents demo skipped: '.$maker->email.' does not hold '.implode(', ', $missing).' (run sales:dev-grant-quotation, which grants the preview duties, then seed again).');

            return;
        }

        try {
            DB::transaction(function () use ($maker, $customer, $service, $pkr): void {
                // The brand and the document are one unit: a failure below leaves neither behind.
                $this->brand($maker);
                // Built by an earlier run (possibly on another day: its dates came from that day): nothing is resubmitted, nothing is added.
                if (DemoQuotationReplay::done($maker, 'demo-doc-draft')) {
                    return;
                }
                $created = app(CreateQuotationDraft::class)($maker, 'demo-doc-draft', ['recipient_party_id' => $customer->id, 'currency_id' => $pkr->id, 'pricing_date' => now()->format('Y-m-d'),
                    'tax_estimate_date' => now()->format('Y-m-d'), 'valid_through_local_date' => now()->addMonth()->format('Y-m-d')]);
                $revision = QuotationRevision::query()->findOrFail($created->revisionId);
                if ($revision->isDraft()) {
                    app(UpdateQuotationDraft::class)($maker, $revision->id, $revision->input_generation, 'demo-doc-lines', ['add_lines' => [
                        ['source_kind' => 'SERVICE_ITEM', 'item_id' => $service->id, 'offered_quantity' => '1', 'unit_price' => '15000.0000'],
                        ['source_kind' => 'SERVICE_ITEM', 'item_id' => $service->id, 'offered_quantity' => '4', 'unit_price' => '42000.0000'],
                        ['source_kind' => 'SERVICE_ITEM', 'item_id' => $service->id, 'offered_quantity' => '1', 'unit_price' => '30000.0000', 'membership' => 'OPTIONAL'],
                    ]]);
                    $revision->refresh();
                    app(IssueQuotation::class)($maker, $revision->id, $revision->input_generation, 'demo-doc-issue');
                }
                app(RequestQuotationDocument::class)($maker, $revision->id, 'demo-doc-generate');
                app(RecordManualQuotationDelivery::class)($maker, $revision->id, ['channel' => 'HAND', 'delivered_on' => now()->format('Y-m-d'), 'recipient_name' => 'Demo customer front desk',
                    'note' => 'Printed copy handed over (demo)'], 'demo-doc-delivery');
                // Journey 1: recorded to the wrong person, then corrected with a reason (the first record stays, marked as replaced).
                $wrong = app(RecordManualQuotationDelivery::class)($maker, $revision->id, ['channel' => 'COURIER', 'delivered_on' => now()->format('Y-m-d'), 'recipient_name' => 'Wrong branch office',
                    'note' => 'Courier parcel (demo)'], 'demo-doc-delivery-courier')['delivery'];
                app(CorrectManualQuotationDelivery::class)($maker, $revision->id, $wrong->id, ['channel' => 'COURIER', 'delivered_on' => now()->format('Y-m-d'), 'recipient_name' => 'Head office reception',
                    'note' => 'Courier parcel (demo)'], 'Recipient was entered wrongly', 'demo-doc-courier-correction');
                // Journey 2: recorded in error, then withdrawn because the parcel was never handed over.
                $never = app(RecordManualQuotationDelivery::class)($maker, $revision->id, ['channel' => 'POST', 'delivered_on' => now()->format('Y-m-d'), 'recipient_name' => 'Postal recipient',
                    'note' => 'Posted copy (demo)'], 'demo-doc-delivery-post')['delivery'];
                app(CorrectManualQuotationDelivery::class)($maker, $revision->id, $never->id, null, 'The posted copy was never sent', 'demo-doc-post-withdrawal');
            });
            $this->say('Quotation documents demo: one issued quotation with its official PDF, a manual delivery record, a corrected delivery and a withdrawn one.');
        } catch (Throwable $e) {
            // Never swallowed (issue #100): the whole transaction is rolled back and the seed run fails loudly, so a half-built demo is not mistaken for a complete one.
            $this->say('Quotation documents demo not built: '.$e->getMessage());

            throw $e;
        }
    }

    /**
     * The demo company's branding (a name, tagline and a small drawn logo), made once through the real Action so the demo PDF is branded. The branding duty is STAGED and stays STAGED:
     * it is switched on in the container and granted to the login only while this runs, and both are put back exactly afterwards. Create-only: a company that already has any brand
     * version is left alone, and the first demo document pins whatever brand exists when it is generated.
     */
    private function brand(User $maker): void
    {
        $path = __DIR__.'/assets/xyz-paints-logo.png';
        if (CompanyBrandVersion::query()->exists() || ! is_file($path)) {
            return;
        }
        Permission::findOrCreate(CompanyDutyManifest::BRANDING, 'web');
        $held = $maker->getDirectPermissions()->pluck('name')->all();
        $manifest = app(CompanyDutyManifest::class);
        $maker->givePermissionTo(CompanyDutyManifest::BRANDING);
        app()->instance(CompanyDutyManifest::class, new CompanyDutyManifest([CompanyDutyManifest::BRANDING => 'ACTIVE']));
        $stream = fopen($path, 'rb');
        try {
            app(SaveCompanyBranding::class)($maker, ['display_name' => 'XYZ Paints', 'tagline' => 'Colour done right'], $stream, 'Demo branding (illustrative logo drawn for the demo)');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            app()->instance(CompanyDutyManifest::class, $manifest);
            if (! in_array(CompanyDutyManifest::BRANDING, $held, true)) {
                $maker->revokePermissionTo(CompanyDutyManifest::BRANDING);
            }
        }
    }

    private function say(string $message): void
    {
        $this->command->getOutput()->writeln('  '.$message);
    }
}
