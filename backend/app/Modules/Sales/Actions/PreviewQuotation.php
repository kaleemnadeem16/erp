<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\Currency;
use App\Models\User;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Financial\Certainty;
use App\Modules\Sales\Support\Financial\CurrencyQuantum;
use App\Modules\Sales\Support\Financial\FinancialResult;
use App\Modules\Sales\Support\Financial\Policy\DraftRevalidation;
use App\Modules\Sales\Support\Financial\Policy\RoundingPolicy;
use App\Modules\Sales\Support\Financial\Policy\RoundingPolicyBinding;
use App\Modules\Sales\Support\Financial\SettlementRule;
use App\Modules\Sales\Support\Fx\FinancialDate;
use App\Modules\Sales\Support\Fx\ResolveQuotationFx;
use App\Modules\Sales\Support\Kernel\SalesActorContext;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Pricing\PriceCandidate;
use App\Modules\Sales\Support\Pricing\PricingInput;
use App\Modules\Sales\Support\Pricing\PricingRefusal;
use App\Modules\Sales\Support\Pricing\PricingSourceRegistry;
use App\Modules\Sales\Support\Pricing\ResolveSalesPrice;
use App\Modules\Sales\Support\Quotation\LineMembership;
use App\Modules\Sales\Support\Quotation\QuotationMoney;
use App\Modules\Sales\Support\Quotation\QuotationRefusal;
use App\Modules\Sales\Support\SalesAccess;
use App\Support\CompanyContext;
use App\Support\Status;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

/**
 * A read-only financial preview of a DRAFT: each line through PR0 source resolution, the rounding policy in force on the pricing date (FR4), the pinned FX
 * evidence (FR1) and the settlement rule, with the document net as the sum of the settled lines. It writes nothing — no receipt, no lock, no candidate — and
 * it is consistent, not authority: saving never adopts it and it makes nothing issuable.
 *
 * Certainty is explicit everywhere. A line with no entered price has an UNKNOWN net; an entered price of exactly zero is a known zero. The document net is
 * COMPLETE only when every counted line is, PARTIAL (a lower bound) when some are, UNKNOWN when none is. Tax is UNKNOWN: determination is not delivered, so
 * nothing here is tax-inclusive or tax-free. Cost is never read into the result: this reader holds no cost duty and the preview carries no cost or margin.
 * Final-net controls need a configured control set and cost-blind evidence, neither of which exists yet, so the preview reports that nothing was evaluated
 * (an empty evaluation is not issue readiness). Guarded by `sales.quotation.prepare` (STAGED).
 */
final class PreviewQuotation
{
    public function __construct(
        private readonly CompanyContext $company,
        private readonly ResolveSalesPrice $prices,
        private readonly PricingSourceRegistry $sources,
        private readonly ResolveQuotationFx $fx,
        private readonly QuotationMoney $money,
    ) {}

    /** @return array<string, mixed> */
    public function __invoke(User $actor, int $revisionId): array
    {
        SalesAccess::require($actor, PermissionVocabulary::QUOTATION_PREPARE, 'preview a quotation');
        $revision = QuotationRevision::query()->find($revisionId)
            ?? throw QuotationRefusal::make(QuotationRefusal::QUOTATION_NOT_FOUND, 'The quotation was not found.', 'revision_id');

        return $this->forRevision($actor, $revision);
    }

    /**
     * The preview of an already-found draft, without the preparer duty check: for the Actions that carry their own guard (issue readiness, issue itself). Nothing else
     * may call it; it checks neither a duty nor an active membership.
     *
     * @internal
     *
     * @return array<string, mixed>
     */
    public function forRevision(User $actor, QuotationRevision $revision): array
    {
        $context = SalesActorContext::forUser($actor, 'quotation.preview');
        if (! $revision->isDraft()) {
            throw QuotationRefusal::make(QuotationRefusal::QUOTATION_NOT_EDITABLE, 'Only a draft quotation can be previewed.', 'revision_id');
        }
        if ($revision->recipient_party_id === null) {
            throw QuotationRefusal::make(QuotationRefusal::RECIPIENT_REQUIRED, 'A preview needs the recipient Party.', 'recipient_party_id');
        }
        if (Party::query()->whereKey($revision->recipient_party_id)->where('status', Status::Active->value)->doesntExist()) {
            throw PricingRefusal::make(PricingRefusal::PARTY_NOT_FOUND, 'The recipient Party was not found or is archived.', 'recipient_party_id');
        }
        $lines = $revision->lines()->get();
        $currency = Currency::query()->findOrFail($revision->currency_id);
        $date = $revision->pricing_date?->format('Y-m-d');
        $policy = $date === null ? null : $this->money->policyOn($date);
        $rule = $policy?->settlementRule($currency);

        $rows = [];
        foreach ($lines as $line) {
            $rows[] = $this->line($context, $revision, $line, $currency->id, $date, $rule);
        }
        // The lines and the generation were read separately; a write in between would make the preview describe a draft that never existed.
        if (QuotationRevision::query()->whereKey($revision->id)->value('input_generation') !== $revision->input_generation) {
            throw QuotationRefusal::make(QuotationRefusal::QUOTATION_DRAFT_CHANGED, 'The draft changed while it was being previewed; preview it again.', 'revision_id');
        }

        [$base, $optional, $issues] = $this->totals($lines, $rows, $currency->id);

        return [
            'revision_id' => $revision->id, 'family_id' => $revision->family_id, 'input_generation' => $revision->input_generation,
            'authority' => 'PREVIEW_ONLY', 'currency_id' => $currency->id, 'pricing_date' => $date,
            'rounding_policy' => $policy?->toArray(), 'revalidation' => $this->revalidation($revision, $policy, $lines),
            'lines' => $rows,
            'totals' => ['net' => $this->certainty($base), 'optional_net' => $optional === null ? null : $this->certainty($optional)],
            'tax' => ['certainty' => Certainty::Unknown->value, 'reasons' => ['TAX_NOT_DETERMINED'], 'total_including_tax' => ['certainty' => Certainty::Unknown->value, 'amount' => null]],
            'controls' => ['evaluated' => 0, 'status' => 'NOT_CONFIGURED', 'blocks_issue' => null],
            'issue_ready' => false, 'issue_blockers' => ['TAX_NOT_DETERMINED', 'ISSUE_NOT_AVAILABLE', 'CONTROLS_NOT_CONFIGURED'],
            'issues' => $issues,
        ];
    }

    /** @return array<string, mixed> */
    private function line(SalesActorContext $context, QuotationRevision $revision, QuotationDraftLine $line, int $currencyId, ?string $date, ?SettlementRule $rule): array
    {
        $row = [
            'id' => $line->id, 'logical_scope_id' => $line->logical_scope_id, 'sort_order' => $line->sort_order, 'section_title' => $line->section_title, 'description' => $line->description,
            'membership' => $line->membership, 'choice_group_id' => $line->choice_group_id, 'is_baseline' => $line->is_baseline, 'source_kind' => $line->source_kind,
            'item_id' => $line->item_id, 'manual_measure' => $line->manual_measure,
            'offered' => ['quantity' => $line->offered_quantity, 'uom_id' => $line->offered_uom_id],
            'priced' => $line->priced_uom_id === null ? null : ['quantity' => $line->priced_quantity, 'uom_id' => $line->priced_uom_id],
            'stock' => $line->stock_uom_id === null ? null : ['quantity' => $line->stock_quantity, 'uom_id' => $line->stock_uom_id, 'conversion_version' => $line->conversion_version],
            'unit_price' => $line->unit_price, 'base_unit_price' => $line->base_unit_price, 'issues' => [],
        ];
        $net = FinancialResult::unknown([['code' => $line->unit_price === null ? 'PRICE_NOT_ENTERED' : 'NET_NOT_COMPUTED']]);

        try {
            $input = new PricingInput($context, (int) $revision->recipient_party_id, $line->pricingSource(), $line->offered_quantity, $line->offered_uom_id, $rule ?? $this->placeholderRule($currencyId));
            if ($line->unit_price === null || $rule === null) {
                // Nothing to settle, but the source is still proven so an archived or changed Item shows up now rather than at issue.
                $this->sources->adapterFor($line->sourceKind())->resolve($input);
                if ($line->unit_price !== null) {
                    $net = FinancialResult::unknown([['code' => 'PRICING_DATE_REQUIRED']]);
                }
            } else {
                $result = ($this->prices)($input, [new PriceCandidate('entered:'.$line->id, 1, (string) $line->unit_price, $currencyId)]);
                $net = FinancialResult::complete($result->line->settled, $currencyId);
                $row['settlement'] = ['computed' => $result->line->computedDecimal() === null ? null : (string) $result->line->computedDecimal(), 'computed_fraction' => $result->line->computedFraction(),
                    'residual' => (string) $result->line->residual()->toBigDecimal()->toScale(8, RoundingMode::HALF_UP)];
            }
        } catch (DomainInvariantException $refusal) {
            $row['issues'][] = (string) $refusal->reasonCode;
            $net = FinancialResult::unknown([['code' => (string) $refusal->reasonCode]]);
        }
        $row['net'] = $this->certainty($net);
        $row['fx'] = $line->fx_fingerprint === null ? null : ['fingerprint' => $line->fx_fingerprint, 'route' => $line->fx_evidence['route'] ?? null, 'ratio' => $line->fx_evidence['ratio'] ?? null,
            'currency_id' => $line->price_currency_id, 'base_currency_id' => $line->base_currency_id, 'current' => $this->fxCurrent($line, $date)];

        return $row;
    }

    /** The evidence stored on the line against what the rate master would give today for the same date: SAME, CHANGED or UNAVAILABLE. Informational — it rewrites nothing. */
    private function fxCurrent(QuotationDraftLine $line, ?string $date): string
    {
        if ($date === null) {
            return 'UNAVAILABLE';
        }
        $company = Company::query()->findOrFail($this->company->id());
        $outcome = $this->fx->attempt((int) $line->price_currency_id, (int) $line->base_currency_id, FinancialDate::explicit($date, $company->timezone, now()));

        return $outcome->evidence === null ? 'UNAVAILABLE' : ($outcome->evidence->fingerprint() === $line->fx_fingerprint ? 'SAME' : 'CHANGED');
    }

    /**
     * @param  Collection<int, QuotationDraftLine>  $lines
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: FinancialResult, 1: FinancialResult|null, 2: list<string>}
     */
    private function totals($lines, array $rows, int $currencyId): array
    {
        $net = [];
        $base = [];
        $optional = [];
        $issues = [];
        $baselined = $lines->where('membership', LineMembership::ChooseOne->value)->where('is_baseline', true)->pluck('choice_group_id')->all();
        foreach ($lines as $index => $line) {
            $result = $rows[$index]['net']['certainty'] === Certainty::Complete->value
                ? FinancialResult::complete($rows[$index]['net']['amount'], $currencyId)
                : FinancialResult::unknown([['code' => $rows[$index]['net']['reasons'][0] ?? 'NET_UNKNOWN']]);
            if ($line->membership === LineMembership::Optional->value) {
                $optional[] = $result;
            } elseif ($line->membership === LineMembership::Base->value || $line->is_baseline) {
                $base[] = $result;
            }
        }
        foreach (array_unique($lines->where('membership', LineMembership::ChooseOne->value)->pluck('choice_group_id')->all()) as $group) {
            if (! in_array($group, $baselined, true)) {
                $base[] = FinancialResult::unknown([['code' => 'CHOICE_BASELINE_MISSING']]);
                $issues[] = "CHOICE_BASELINE_MISSING:{$group}";
            }
        }

        return [
            $base === [] ? FinancialResult::unknown([['code' => 'NO_LINES']]) : FinancialResult::sum($base),
            $optional === [] ? null : FinancialResult::sum($optional),
            $issues,
        ];
    }

    /**
     * @param  Collection<int, QuotationDraftLine>  $lines
     * @return array<string, mixed>|null
     */
    private function revalidation(QuotationRevision $revision, ?RoundingPolicy $policy, $lines): ?array
    {
        if ($revision->rounding_policy_fingerprint === null || $policy === null) {
            return null;
        }
        $prices = $lines->whereNotNull('unit_price')->mapWithKeys(fn (QuotationDraftLine $line): array => ['line:'.$line->id => (string) $line->unit_price])->all();
        $review = DraftRevalidation::review(new RoundingPolicyBinding($revision->rounding_policy_fingerprint, $revision->rounding_policy_version_no, $revision->rounding_policy_as_of->format('Y-m-d')), $policy, $prices);

        return ['requires_review' => $review->requiresReview(), 'reasons' => $review->reasons, 'non_conforming_lines' => array_map(static fn (string $key): int => (int) substr($key, 5), $review->nonConforming)];
    }

    private function placeholderRule(int $currencyId): SettlementRule
    {
        // Without a pricing date there is no policy, so nothing is settled; the rule only satisfies the input type for proving a source.
        return new SettlementRule($currencyId, new CurrencyQuantum(2), RoundingMode::HALF_UP);
    }

    /** @return array<string, mixed> */
    private function certainty(FinancialResult $result): array
    {
        return [
            'certainty' => $result->certainty->value, 'amount' => $result->amount === null ? null : (string) $result->amount,
            'known_amount' => $result->knownAmount === null ? null : (string) $result->knownAmount, 'currency_id' => $result->currencyId,
            'reasons' => array_column($result->reasons, 'code'),
        ];
    }
}
