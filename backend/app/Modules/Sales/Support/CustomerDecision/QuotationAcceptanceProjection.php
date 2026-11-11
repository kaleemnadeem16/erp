<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\CustomerDecision;

use App\Models\User;
use App\Modules\Sales\Models\CustomerAcceptanceLine;
use App\Modules\Sales\Models\CustomerScopeClaimHead;
use App\Modules\Sales\Models\QuotationIssuedLine;
use App\Modules\Sales\Models\QuotationLogicalScope;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\PermissionVocabulary as P;
use App\Modules\Sales\Support\Quotation\QuotationAvailability;
use App\Modules\Sales\Support\SalesAccess;

/** @internal Safe per-scope flags only; no competing family, acceptance id, quantity or actor. Caller holds quotation read fence. */
final class QuotationAcceptanceProjection
{
    /** @return list<array{issued_line_id: int, logical_scope_id: int, status: string}>|null */
    public function for(User $actor, QuotationRevision $revision): ?array
    {
        if (! SalesAccess::may($actor, P::CUSTOMER_DECISION_VIEW)) {
            return null;
        }
        $available = QuotationAvailability::of($revision) === QuotationAvailability::AVAILABLE;
        $lines = QuotationIssuedLine::query()->where('revision_id', $revision->id)->orderBy('id')->get();
        $accepted = CustomerAcceptanceLine::query()->whereIn('issued_line_id', $lines->modelKeys())->pluck('issued_line_id')->all();
        $heads = CustomerScopeClaimHead::query()->where('family_id', $revision->family_id)->get();
        $anchors = QuotationLogicalScope::query()->whereIn('id', $lines->pluck('logical_scope_id'))->get()->keyBy('id');

        return $lines->map(function (QuotationIssuedLine $line) use ($accepted, $heads, $anchors, $available): array {
            $root = $anchors[$line->logical_scope_id]->effectiveAnchorId();
            $claimed = $heads->contains(fn (CustomerScopeClaimHead $h): bool => $h->consumed > 0 && ($h->scope_anchor_id === $root || ($line->choice_group_id !== null && $h->choice_group_id === $line->choice_group_id)));

            return ['issued_line_id' => $line->id, 'logical_scope_id' => $line->logical_scope_id,
                'status' => in_array($line->id, $accepted, true) ? 'ACCEPTED' : ($claimed ? 'CLAIMED' : ($available ? 'AVAILABLE' : 'UNAVAILABLE'))];
        })->all();
    }
}
