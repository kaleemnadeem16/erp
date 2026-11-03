<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Sales;

use App\Models\Currency;
use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Actions\AssessQuotationIssueReadiness;
use App\Modules\Sales\Actions\CancelQuotationDraft;
use App\Modules\Sales\Actions\CreateQuotationDraft;
use App\Modules\Sales\Actions\IssueQuotation;
use App\Modules\Sales\Actions\UpdateQuotationDraft;
use App\Modules\Sales\Actions\WithdrawQuotationOffer;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Authority\SalesDevPreview;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as Sales;
use App\Modules\Tax\Actions\ActivateTaxGroup;
use App\Modules\Tax\Actions\ActivateTaxQuotationDeclaration;
use App\Modules\Tax\Actions\CreateTaxComponent;
use App\Modules\Tax\Actions\CreateTaxQuotationDeclarationSuccessor;
use App\Modules\Tax\Actions\CreateTaxVersionSuccessor;
use App\Modules\Tax\Actions\DefineTaxGroupGraph;
use App\Modules\Tax\Support\EffectivePeriod;
use App\Modules\Tax\Support\TaxDutyManifest;
use App\Modules\Tax\Support\TaxVersionKind;
use App\Support\CompanyContext;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;

/**
 * Issued quotations for XYZ Paints, created only through the real Actions as the demo administrator (owner decision, #76: a LOCAL-ONLY dev grant for the demo seeders).
 *
 * Local only: it runs when `SalesDevPreview::enabled()` (APP_ENV=local AND SALES_DEV_QUOTATION_DUTIES=true); anywhere else it logs one line and writes nothing. The `issue` and
 * `withdraw` duties are STAGED and stay STAGED: they are switched on only while this seeder runs (the manifest instance is put back afterwards, as the other quotation seeders do) and
 * the Tax configuration duty is granted for the run and taken away again. No tax or control evidence is faked: a fictitious "demo-illustrative" tax declaration (10% on the demo
 * service classification, not a legal tax rule) is built through Tax's own Actions, and the control set is the one `QuotationDemoSeeder` published. A rerun replays every request key.
 *
 *  - READY      A draft for Alpha Decorators with a declared service line, complete and unissued: Issue readiness says it can be issued.
 *  - ISSUED     The same shape, issued: official number, frozen terms, totals.
 *  - EXPIRING   Issued with a valid-through of TODAY: it is open now and becomes Expired at the next local midnight, with no scheduler (an expired offer cannot be built earlier through
 *               the real Actions, which refuse a past valid-through and never rewrite an issued record).
 *  - WITHDRAWN  Issued, then withdrawn by the seller with a reason.
 *  - CANCELLED  A draft the seller cancelled with a reason.
 *  - BLOCKED    A draft whose line is an Item with no tax declaration: Issue readiness reports TAX_CLASSIFICATION_REQUIRED.
 */
final class QuotationIssuingDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    private const SALES_DUTIES = [Sales::QUOTATION_VIEW, Sales::QUOTATION_PREPARE, Sales::QUOTATION_ISSUE, Sales::QUOTATION_WITHDRAW];

    private const JURISDICTION = 'demo-illustrative';

    public function run(): void
    {
        $this->ensureDemoEnvironment();
        if (! SalesDevPreview::enabled()) {
            Log::info('Quotation issuing demo skipped: needs APP_ENV=local and SALES_DEV_QUOTATION_DUTIES=true (the issue duty stays STAGED everywhere else).');

            return;
        }
        $company = app(CompanyContext::class)->id();
        $admin = User::query()->where('company_id', $company)->where('email', config('demo.user.email'))->firstOrFail();
        $alpha = Party::query()->where('code', 'cus-alpha')->first();
        $declared = Item::query()->where('code', 'demo-site-application')->first();
        $undeclared = Item::query()->where('code', 'demo-paint-white')->first();
        $pkr = Currency::query()->where('code', 'PKR')->first();
        if ($alpha === null || $declared === null || $undeclared === null || $pkr === null || ! DB::table('tax_quotation_jurisdictions')->where('company_id', $company)->exists()) {
            Log::info('Quotation issuing demo skipped: the demo customer, items or tax source facts do not exist yet.');

            return;
        }

        foreach ([...self::SALES_DUTIES, TaxDutyManifest::CONFIGURE] as $duty) {
            Permission::findOrCreate($duty, 'web');
        }
        $held = $admin->getAllPermissions()->pluck('name')->all();
        $admin->givePermissionTo([...self::SALES_DUTIES, TaxDutyManifest::CONFIGURE]);
        $sales = app(SalesDutyManifest::class);
        $tax = app(TaxDutyManifest::class);
        $staged = array_values(array_filter(self::SALES_DUTIES, fn (string $duty): bool => ! $sales->isActive($duty)));
        app()->instance(SalesDutyManifest::class, $staged === [] ? $sales : $sales->withActivated($staged));
        app()->instance(TaxDutyManifest::class, new TaxDutyManifest([TaxDutyManifest::CONFIGURE => 'ACTIVE', TaxDutyManifest::PROFILE => 'STAGED']));
        try {
            DB::transaction(function () use ($admin, $alpha, $declared, $undeclared, $pkr): void {
                $this->declaration($admin, $pkr);
                $this->quotations($admin, $alpha, $declared, $undeclared, $pkr);
            });
        } finally {
            app()->instance(SalesDutyManifest::class, $sales);
            app()->instance(TaxDutyManifest::class, $tax);
            // The Tax duty was granted for this run only; the Sales duties are the demo administrator's, as QuotationDemoSeeder grants them.
            if (! in_array(TaxDutyManifest::CONFIGURE, $held, true)) {
                $admin->revokePermissionTo(TaxDutyManifest::CONFIGURE);
            }
        }
    }

    private function generation(): int
    {
        return (int) DB::table('tax_generations')->where('company_id', app(CompanyContext::class)->id())->value('generation');
    }

    /** A fictitious service tax (10%) for the demo service classification, through Tax's own Actions; built once. */
    private function declaration(User $admin, Currency $pkr): void
    {
        if (DB::table('tax_quotation_declaration_versions')->where('code', self::JURISDICTION)->exists()) {
            return;
        }
        $period = new EffectivePeriod('2026-01-01', '2028-01-01');
        $component = app(CreateTaxComponent::class)($admin, 'demo-service-tax', 'demo-identity-service-tax', $this->generation());
        $make = fn (TaxVersionKind $kind, array $definition): int => app(CreateTaxVersionSuccessor::class)($admin, $kind, 'demo-service-tax', null, $period, $definition,
            'Fictitious demo tax, not a legal rule', 'demo-'.$kind->value.'-service-tax', $this->generation());
        $componentVersion = $make(TaxVersionKind::Component, ['component_id' => $component, 'name' => 'Demo service tax (illustrative)']);
        $rate = $make(TaxVersionKind::Rate, ['component_id' => $component, 'percentage' => '10.000000000']);
        $rule = $make(TaxVersionKind::Rule, ['component_id' => $component, 'component_version_id' => $componentVersion, 'rate_version_id' => $rate, 'method' => 'PERCENT', 'base' => 'NET',
            'dependency_stage' => 'RAW', 'required_mode' => 'BASIC', 'nature' => 'SERVICES', 'classification' => 'demo-service', 'jurisdiction' => self::JURISDICTION]);
        $group = $make(TaxVersionKind::Group, ['required_mode' => 'BASIC', 'currency_id' => $pkr->id]);
        app(DefineTaxGroupGraph::class)($admin, $group, [['component_id' => $component, 'rule_version_id' => $rule, 'predecessors' => []]], 'demo-graph-service-tax', $this->generation());
        app(ActivateTaxGroup::class)($admin, $group, 'Fictitious demo tax', 'demo-activate-service-tax', $this->generation());
        $coverage = [['nature' => 'SERVICES', 'classification' => 'demo-service', 'group_version_id' => $group, 'component_id' => $component, 'rule_version_id' => $rule,
            'disposition' => 'TAXABLE', 'requires_party_profile' => false, 'grounds' => 'Fictitious demo declaration']];
        $declaration = app(CreateTaxQuotationDeclarationSuccessor::class)($admin, self::JURISDICTION, self::JURISDICTION, null, $period, 'HALF_UP', $coverage,
            'Fictitious demo declaration, not a legal tax rule', 'demo-declare-quotation-tax', $this->generation());
        app(ActivateTaxQuotationDeclaration::class)($admin, $declaration, 'Fictitious demo declaration', 'demo-activate-quotation-tax', $this->generation());
    }

    private function quotations(User $admin, Party $alpha, Item $declared, Item $undeclared, Currency $pkr): void
    {
        $today = now()->format('Y-m-d');
        $header = fn (string $validThrough): array => ['recipient_party_id' => $alpha->id, 'currency_id' => $pkr->id, 'pricing_date' => $today, 'tax_estimate_date' => $today, 'valid_through_local_date' => $validThrough];
        $service = ['source_kind' => 'SERVICE_ITEM', 'item_id' => $declared->id, 'offered_quantity' => '2', 'unit_price' => '25000.0000'];
        $month = now()->addMonths(2)->format('Y-m-d');

        $ready = $this->draft($admin, 'ready', $header($month), [$service]);
        $this->assertReady($ready);

        $issued = $this->draft($admin, 'issued', $header($month), [$service]);
        $this->issue($admin, 'issued', $issued);

        $expiring = $this->draft($admin, 'expiring', $header($today), [$service]);
        $this->issue($admin, 'expiring', $expiring);

        $withdrawn = $this->draft($admin, 'withdrawn', $header($month), [$service]);
        $this->issue($admin, 'withdrawn', $withdrawn);
        app(WithdrawQuotationOffer::class)($admin, $withdrawn->id, 'demo-iss-withdraw', 'Priced against the wrong scope; a corrected quotation will follow.');

        $cancelled = $this->draft($admin, 'cancelled', $header($month), [$service]);
        app(CancelQuotationDraft::class)($admin, $cancelled->id, QuotationRevision::query()->findOrFail($cancelled->id)->input_generation, 'demo-iss-cancel', 'The customer withdrew the enquiry.');

        $this->draft($admin, 'blocked', $header($month), [['source_kind' => 'DIRECT_ITEM', 'item_id' => $undeclared->id, 'offered_quantity' => '4', 'unit_price' => '8000.0000']]);
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array<string, mixed>>  $lines
     */
    private function draft(User $admin, string $marker, array $header, array $lines): QuotationRevision
    {
        $created = app(CreateQuotationDraft::class)($admin, "demo-iss-{$marker}", $header);
        $revision = QuotationRevision::query()->findOrFail($created->revisionId);
        if ($revision->isDraft() && $revision->lines()->doesntExist()) {
            app(UpdateQuotationDraft::class)($admin, $revision->id, $revision->input_generation, "demo-iss-{$marker}-lines", ['add_lines' => $lines]);
        }

        return $revision->refresh();
    }

    private function issue(User $admin, string $marker, QuotationRevision $revision): void
    {
        app(IssueQuotation::class)($admin, $revision->id, $revision->input_generation, "demo-iss-{$marker}-issue");
    }

    /** The ready scenario must really be ready: if tax or controls disagree the seeder says so instead of showing a lie. */
    private function assertReady(QuotationRevision $revision): void
    {
        $result = app(AssessQuotationIssueReadiness::class)(User::query()->where('email', config('demo.user.email'))->firstOrFail(), $revision->id);
        if ($result['issue_ready'] !== true) {
            Log::warning('Quotation issuing demo: the READY draft is not ready: '.implode(', ', array_column($result['blockers'], 'code')));
        }
    }
}
