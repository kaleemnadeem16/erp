<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Sales;

use App\Models\Currency;
use App\Models\User;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Actions\CreateQuotationDraft;
use App\Modules\Sales\Actions\UpdateQuotationDraft;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as Sales;
use App\Modules\Sales\Support\Quotation\QuotationDraftOutcome;
use App\Support\CompanyContext;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;

/**
 * Sales quotation CONCESSIONS (PR4/PR5) for XYZ Paints, through the module's own Actions as the demo administrator. The quotation duties are STAGED; they are switched on only while this
 * seeder runs (as `QuotationDemoSeeder` does) and the manifest is put back afterwards, so nothing here activates a duty anywhere. Fictitious figures only. A rerun replays every
 * request key and changes nothing. The company's routine-discount limit (10% of the list amount) is published by `QuotationDemoSeeder`, which runs first.
 *
 *  - WITHIN LIMIT   A 10% line discount, at the limit: recorded with who entered it, no approval needed.
 *  - ABOVE LIMIT    A 25% discount: approval would be needed (DISCOUNT_ABOVE_LIMIT), which keeps the draft from being issued.
 *  - OVERRIDE       A reasoned final-net override of a lump-sum line: always needs approval (PRICE_OVERRIDE).
 *  - TAX         A manual line whose tax classification is declared, then corrected (version 2); Tax has no declared rate here, so the tax stays honestly unknown.
 *  - DOCUMENT       A 5% document discount spread over the counted lines; an optional line takes no share.
 */
final class QuotationConcessionsDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    private const DUTIES = [Sales::QUOTATION_VIEW, Sales::QUOTATION_PREPARE, Sales::QUOTATION_DISCOUNT, Sales::QUOTATION_PRICE_OVERRIDE];

    public function run(): void
    {
        $this->seedFor(app(CompanyContext::class)->id());
    }

    /** Create-only for one named company: every request key replays, nothing existing is edited, and another company's data is never read. */
    public function seedFor(int $companyId): void
    {
        $this->ensureDemoEnvironment();
        // Like the other quotation drafts, only where the local preview already makes prepare/view usable; anywhere else it logs one line and does nothing.
        if (! app(SalesDutyManifest::class)->isActive(Sales::QUOTATION_PREPARE)) {
            Log::info('Quotation concession drafts skipped: sales.quotation.prepare is STAGED here (local preview: APP_ENV=local and SALES_DEV_QUOTATION_DUTIES=true).');

            return;
        }
        $admin = User::query()->where('company_id', $companyId)->where('email', config('demo.user.email'))->firstOrFail();
        foreach (self::DUTIES as $duty) {
            Permission::findOrCreate($duty, 'web');
        }
        // Only what this run adds is taken away again afterwards (a grant the login already held directly is left exactly as it was).
        $heldBefore = $admin->getDirectPermissions()->pluck('name')->all();
        $admin->givePermissionTo(self::DUTIES);

        $shipped = app(SalesDutyManifest::class);
        $staged = array_values(array_filter(self::DUTIES, fn (string $duty): bool => ! $shipped->isActive($duty)));
        app()->instance(SalesDutyManifest::class, $staged === [] ? $shipped : $shipped->withActivated($staged));
        try {
            DB::transaction(fn () => $this->seed($admin));
        } finally {
            app()->instance(SalesDutyManifest::class, $shipped);
            $added = array_values(array_diff(self::DUTIES, $heldBefore));
            if ($added !== []) {
                $admin->revokePermissionTo($added);
            }
        }
    }

    private function seed(User $admin): void
    {
        $alpha = Party::query()->where('code', 'cus-alpha')->firstOrFail();
        $metro = Party::query()->where('code', 'cus-metro')->firstOrFail();
        $pkr = Currency::query()->where('code', 'PKR')->firstOrFail();
        $day = '2026-10-12';
        $header = fn (Party $to): array => ['recipient_party_id' => $to->id, 'currency_id' => $pkr->id, 'pricing_date' => $day, 'tax_estimate_date' => $day, 'valid_through_local_date' => '2026-12-31'];
        $line = fn (string $ref, string $text, string $qty, string $price, array $more = []): array => ['source_kind' => 'MANUAL', 'description' => $text, 'manual_measure' => 'job',
            'offered_quantity' => $qty, 'unit_price' => $price, 'scope_ref' => $ref] + $more;

        $within = $this->draft($admin, 'within', $header($alpha));
        $this->change($admin, $within, 'within-lines', ['add_lines' => [
            $line('wall', 'Wall repaint, interior', '10', '12500.00', ['discount' => ['kind' => 'PERCENT', 'value' => '10']]),
            $line('prep', 'Surface preparation', '1', '40000.00'),
        ]]);

        $above = $this->draft($admin, 'above', $header($metro));
        $this->change($admin, $above, 'above-lines', ['add_lines' => [
            $line('coat', 'Plant room coating system', '1', '300000.00', ['discount' => ['kind' => 'PERCENT', 'value' => '25']]),
        ]]);

        $override = $this->draft($admin, 'override', $header($alpha));
        $this->change($admin, $override, 'override-lines', ['add_lines' => [
            $line('survey', 'Site survey and report', '1', '80000.00', ['net_override' => ['amount' => '65000', 'reason' => 'Existing customer: survey fee reduced after the site visit.']]),
        ]]);

        // TAX: a manual line has no Item for Tax to read, so the seller declares its nature and classification; the correction is the next version, the first stays on record.
        $tax = $this->draft($admin, 'tax', $header($alpha));
        $step = $this->change($admin, $tax, 'tax-lines', ['add_lines' => [
            $line('install', 'Installation labour and materials', '1', '90000.00', ['tax_declaration' => ['nature' => 'SERVICES', 'classification' => 'demo-service', 'reason' => 'Entered as labour only.']]),
        ]]);
        $lineId = (int) DB::table('quotation_draft_lines')->where('revision_id', $tax->revisionId)->value('id');
        $this->change($admin, $step, 'tax-correction', ['update_lines' => [['id' => $lineId, 'tax_declaration' => ['nature' => 'GOODS', 'classification' => 'demo-goods', 'reason' => 'Materials are most of the value: corrected to goods.']]]]);

        $document = $this->draft($admin, 'document', $header($metro));
        $step = $this->change($admin, $document, 'document-lines', ['add_lines' => [
            $line('floor', 'Warehouse floor coating', '1', '250000.00'),
            $line('line-marking', 'Line marking', '1', '45000.00'),
            $line('sealant', 'Optional joint sealant', '1', '18000.00', ['membership' => 'OPTIONAL']),
        ]]);
        $this->change($admin, $step, 'document-discount', ['document_discount' => ['kind' => 'PERCENT', 'value' => '5']]);
    }

    /** @param  array<string, mixed>  $header */
    private function draft(User $by, string $marker, array $header): QuotationDraftOutcome
    {
        return app(CreateQuotationDraft::class)($by, "demo-conc-{$marker}", $header);
    }

    /** @param  array<string, mixed>  $input */
    private function change(User $by, QuotationDraftOutcome $draft, string $marker, array $input): QuotationDraftOutcome
    {
        return app(UpdateQuotationDraft::class)($by, $draft->revisionId, $draft->inputGeneration, "demo-conc-{$marker}", $input);
    }
}
