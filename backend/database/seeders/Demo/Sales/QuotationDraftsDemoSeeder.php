<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Sales;

use App\Models\Currency;
use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Actions\CreateQuotationDraft;
use App\Modules\Sales\Actions\UpdateQuotationDraft;
use App\Modules\Sales\Models\Opportunity;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as Sales;
use App\Modules\Sales\Support\Quotation\QuotationDraftOutcome;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sales quotation DRAFTS (QL0/QL1a/QL1b) for XYZ Paints, created only through the module's own Actions by a dedicated demo preparer. Nothing here is priced,
 * approved, taxed, numbered or issued: a draft stores the seller's inputs, and the read-only preview (PreviewQuotation) shows what they would settle to.
 *
 * Authority: `sales.quotation.prepare` and `sales.quotation.view` are STAGED duties. This seeder does not activate or hack them: it runs only when the container's
 * duty manifest already lists `sales.quotation.prepare` as ACTIVE, which the shipped manifest never does. On a developer machine that is the existing local
 * preview (`APP_ENV=local` and `SALES_DEV_QUOTATION_DUTIES=true`, see the Sales README). Anywhere else (including the test environment) it logs one line and does nothing,
 * so `demo:seed` still succeeds. When it runs, only the dedicated persona below is granted the two duties; the demo login is untouched (use `sales:dev-grant-quotation`).
 *
 * Idempotency: every write carries a stable request key (`demo-quote-...`), so a rerun replays the recorded results and changes nothing, including after a person has
 * edited a demo draft by hand. Scenarios (code in the first line of each comment is the marker in the keys):
 *  - SIMPLE   A complete PKR draft for Alpha Decorators, linked to no deal: one DIRECT_ITEM line (finished paint) and one MANUAL line (surface preparation), both priced.
 *  - DEAL     A draft linked to the open "Plant room refurbishment" deal of Metro Industries (the recipient must match the deal's Party); an unpriced line stays UNKNOWN.
 *  - INCOMPLETE  A draft with no recipient, no pricing date and an unpriced line, edited three times: it is fine to save, but the preview refuses it until a recipient exists.
 *  - CHOICES  An OPTIONAL extra line and a CHOOSE-ONE group (a baseline plus an alternative), the shapes that need a decision from the customer.
 *  - USD      A US-dollar draft for a PKR company: the entered USD price is stored with its pinned USD to PKR rate (from CurrencyRatesDemoSeeder, 12 October) and the base amount.
 */
final class QuotationDraftsDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    public const PREPARER = 'demo-quote-preparer@erp.x1techs.com';

    public function run(): void
    {
        $this->ensureDemoEnvironment();
        if (! app(SalesDutyManifest::class)->isActive(Sales::QUOTATION_PREPARE)) {
            $this->command?->getOutput()->writeln('  Quotation drafts skipped: sales.quotation.prepare is STAGED here (local preview: APP_ENV=local and SALES_DEV_QUOTATION_DUTIES=true).');

            return;
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        foreach ([Sales::QUOTATION_PREPARE, Sales::QUOTATION_VIEW, Sales::PIPELINE_VIEW] as $duty) {
            Permission::findOrCreate($duty, 'web');
        }
        $preparer = $this->demoMember(self::PREPARER, 'Demo Quotation Preparer');
        $preparer->givePermissionTo([Sales::QUOTATION_PREPARE, Sales::QUOTATION_VIEW, Sales::PIPELINE_VIEW]);

        DB::transaction(function () use ($preparer): void {
            $alpha = Party::query()->where('code', 'cus-alpha')->firstOrFail();
            $metro = Party::query()->where('code', 'cus-metro')->firstOrFail();
            $paint = Item::query()->where('code', 'demo-paint-white')->firstOrFail();
            $thinner = Item::query()->where('code', 'demo-thinner')->firstOrFail();
            $pkr = Currency::query()->where('code', 'PKR')->firstOrFail();
            $usd = Currency::query()->where('code', 'USD')->firstOrFail();
            $day = '2026-10-12';

            $manual = fn (string $text, string $qty, ?string $price, array $more = []): array => ['source_kind' => 'MANUAL', 'description' => $text, 'manual_measure' => 'job', 'offered_quantity' => $qty, 'unit_price' => $price] + $more;
            $direct = fn (Item $item, string $qty, ?string $price, array $more = []): array => ['source_kind' => 'DIRECT_ITEM', 'item_id' => $item->id, 'offered_quantity' => $qty, 'unit_price' => $price] + $more;

            // SIMPLE: everything a seller needs for a first quotation, priced in the company currency.
            $simple = $this->draft($preparer, 'simple', ['recipient_party_id' => $alpha->id, 'currency_id' => $pkr->id, 'pricing_date' => $day, 'tax_estimate_date' => $day, 'valid_through_local_date' => '2026-11-12']);
            $this->change($preparer, $simple, 'simple-lines', ['add_lines' => [
                $direct($paint, '40', '18500.00', ['scope_ref' => 'paint', 'section_title' => 'Supply']),
                $manual('Surface preparation (lump sum)', '1', '120000.00', ['scope_ref' => 'prep', 'section_title' => 'Works']),
            ]]);

            // DEAL: linked to the open deal; the unpriced line is an unknown amount, not a zero.
            $deal = Opportunity::query()->where('title', 'Plant room refurbishment')->first();
            $linked = $this->draft($preparer, 'deal', ['recipient_party_id' => $metro->id, 'currency_id' => $pkr->id, 'pricing_date' => $day, 'opportunity_id' => $deal?->id]);
            $this->change($preparer, $linked, 'deal-lines', ['add_lines' => [
                $direct($thinner, '500', '640.00', ['scope_ref' => 'thinner']),
                $manual('Plant room coating system (price to be confirmed on site visit)', '1', null, ['scope_ref' => 'coating']),
            ]]);

            // INCOMPLETE: saved early with almost nothing, then edited as information arrives; every save moves the input generation.
            $early = $this->draft($preparer, 'incomplete', ['currency_id' => $pkr->id]);
            $step = $this->change($preparer, $early, 'incomplete-1', ['add_lines' => [$manual('Warehouse floor coating (scope to be measured)', '1', null, ['scope_ref' => 'floor'])]]);
            $step = $this->change($preparer, $step, 'incomplete-2', ['valid_through_local_date' => '2026-11-30']);
            $this->change($preparer, $step, 'incomplete-3', ['add_lines' => [$direct($paint, '10', null, ['scope_ref' => 'paint'])]]);

            // CHOICES: an optional extra and a choose-one group with exactly one baseline.
            $choices = $this->draft($preparer, 'choices', ['recipient_party_id' => $alpha->id, 'currency_id' => $pkr->id, 'pricing_date' => $day]);
            $this->change($preparer, $choices, 'choices-lines', ['add_lines' => [
                $manual('Head-office repaint, standard finish', '1', '450000.00', ['scope_ref' => 'finish-standard', 'membership' => 'CHOOSE_ONE', 'choice_group_ref' => 'finish', 'is_baseline' => true]),
                $manual('Head-office repaint, premium low-VOC finish', '1', '560000.00', ['scope_ref' => 'finish-premium', 'membership' => 'CHOOSE_ONE', 'choice_group_ref' => 'finish']),
                $direct($thinner, '100', '640.00', ['scope_ref' => 'thinner', 'membership' => 'OPTIONAL']),
            ]]);

            // USD: a foreign-currency quotation; the price is stored with the pinned rate and its base-currency amount.
            $dollar = $this->draft($preparer, 'usd', ['recipient_party_id' => $metro->id, 'currency_id' => $usd->id, 'pricing_date' => $day]);
            $this->change($preparer, $dollar, 'usd-lines', ['add_lines' => [$manual('Imported anti-corrosion coating, supply only', '2', '1250.00', ['scope_ref' => 'import'])]]);
        });
    }

    /** @param  array<string, mixed>  $header */
    private function draft(User $by, string $marker, array $header): QuotationDraftOutcome
    {
        return app(CreateQuotationDraft::class)($by, "demo-quote-{$marker}", array_filter($header, static fn (mixed $v): bool => $v !== null));
    }

    /** @param  array<string, mixed>  $input */
    private function change(User $by, QuotationDraftOutcome $draft, string $marker, array $input): QuotationDraftOutcome
    {
        return app(UpdateQuotationDraft::class)($by, $draft->revisionId, $draft->inputGeneration, "demo-quote-{$marker}", $input);
    }
}
