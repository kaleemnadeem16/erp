<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Models\QuotationIssue;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\CustomerDecision\QuotationAcceptanceProjection;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\QuotationAccess;
use App\Modules\Sales\Support\Quotation\QuotationAvailability;
use App\Modules\Sales\Support\Quotation\QuotationRefusal;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The official record of an ISSUED quotation revision for a person who may see it: the official number, the issue instant, the maker and the checker, and the frozen
 * header terms (currency, pricing date, valid-through, expiry). Needs `sales.quotation.view` for this revision through the quotation-family policy (OWN = its current
 * responsible owner, ALL = the company), under the company access fence held through the read. A revision that is unknown, in another company, outside the caller's
 * scope or not issued is the same QUOTATION_NOT_FOUND.
 *
 * The settled totals (net, optional net, tax, gross) are included only for a caller who also holds prepare or issue for this revision: the people who already see
 * those figures in the preview and at issue. Nothing else leaves: no cost, margin, control outcome, fingerprint, tax evidence, policy or numbering reference.
 */
final class ReadIssuedQuotation
{
    /** @return array{revision_id: int, family_id: int, official_number: string, issued_at: string, prepared_by: int, issued_by: int, currency_id: int, pricing_date: string, valid_through_local_date: string, expires_at: string, availability: string, scope_acceptance: list<array{issued_line_id: int, logical_scope_id: int, status: string}>|null, totals: array{net_total: string, optional_net_total: string|null, tax_total: string|null, gross_total: string|null}|null} */
    public function __invoke(User $actor, int $revisionId): array
    {
        return DB::transaction(function () use ($actor, $revisionId): array {
            $revision = app(QuotationAccess::class)->requireRevision($actor, $revisionId, [PermissionVocabulary::QUOTATION_VIEW]);
            $issue = $revision->state === 'ISSUED' ? QuotationIssue::query()->where('revision_id', $revision->id)->first() : null;
            if ($issue === null) {
                throw QuotationRefusal::make(QuotationRefusal::QUOTATION_NOT_FOUND, 'The quotation was not found.', 'revision_id');
            }
            $figures = $this->reaches($actor, $revision, PermissionVocabulary::QUOTATION_PREPARE) || $this->reaches($actor, $revision, PermissionVocabulary::QUOTATION_ISSUE);

            return [
                'revision_id' => (int) $revision->id,
                'family_id' => (int) $revision->family_id,
                'official_number' => $issue->official_number,
                'issued_at' => $issue->issued_at->toIso8601String(),
                'prepared_by' => (int) $issue->revision_created_by,
                'issued_by' => (int) $issue->issued_by,
                'currency_id' => (int) $issue->currency_id,
                'pricing_date' => $issue->pricing_date->format('Y-m-d'),
                'valid_through_local_date' => $issue->valid_through_local_date->format('Y-m-d'),
                'expires_at' => $issue->expires_at->toIso8601String(),
                // Derived, never stored: AVAILABLE, EXPIRED (database clock), WITHDRAWN or REPLACED. Says nothing about acceptance.
                'availability' => QuotationAvailability::of($revision),
                'scope_acceptance' => app(QuotationAcceptanceProjection::class)->for($actor, $revision),
                'totals' => $figures ? [
                    'net_total' => (string) $issue->net_total,
                    'optional_net_total' => $issue->optional_net_total === null ? null : (string) $issue->optional_net_total,
                    'tax_total' => $issue->tax_total === null ? null : (string) $issue->tax_total,
                    'gross_total' => $issue->gross_total === null ? null : (string) $issue->gross_total,
                ] : null,
            ];
        });
    }

    /** A savepoint under the fence this transaction already holds; any refusal means "no". */
    private function reaches(User $actor, QuotationRevision $revision, string $duty): bool
    {
        try {
            DB::transaction(fn () => app(QuotationAccess::class)->requireRevision($actor, (int) $revision->id, [$duty]));

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
