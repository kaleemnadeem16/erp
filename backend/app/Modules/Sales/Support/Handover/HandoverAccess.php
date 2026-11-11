<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Handover;

use App\Models\User;
use App\Modules\Sales\Models\CustomerAcceptance;
use App\Modules\Sales\Models\CustomerAcceptanceLine;
use App\Modules\Sales\Models\CustomerDecisionCase;
use App\Modules\Sales\Models\CustomerScopeClaimHead;
use App\Modules\Sales\Models\QuotationIssuedLine;
use App\Modules\Sales\Support\CustomerDecision\CustomerDecisionAccess;
use App\Modules\Sales\Support\DiscountAuthority\AuthorityFence;
use App\Modules\Sales\Support\PermissionVocabulary as P;
use App\Modules\Sales\Support\Quotation\QuotationAccess;
use App\Modules\Sales\Support\SalesAccess;

/** @internal Fresh actor/fence first, authority head second, accepted/source rows last. Shared with readiness. */
final class HandoverAccess
{
    public static function early(User $actor, bool $preparing): void
    {
        CustomerDecisionAccess::early($actor, $preparing ? P::HANDOVER_PREPARE : P::CUSTOMER_DECISION_VIEW, 'prepare or read a handover');
        SalesAccess::require($actor, P::CUSTOMER_DECISION_VIEW, 'see customer decisions');
    }

    public function lock(User $actor, int $acceptanceId, bool $preparing = true): HandoverSubject
    {
        $fresh = CustomerDecisionAccess::decide($actor, $preparing ? P::HANDOVER_PREPARE : P::CUSTOMER_DECISION_VIEW, 'prepare or read a handover');
        SalesAccess::require($fresh, P::CUSTOMER_DECISION_VIEW, 'see customer decisions');
        app(AuthorityFence::class)->lock();
        $a = CustomerAcceptance::query()->lockForUpdate()->findOrFail($acceptanceId);
        $case = CustomerDecisionCase::query()->lockForUpdate()->findOrFail($a->case_id);
        $line = CustomerAcceptanceLine::query()->where('acceptance_id', $a->id)->firstOrFail();
        $issued = QuotationIssuedLine::query()->findOrFail($line->issued_line_id);
        CustomerScopeClaimHead::query()->where('family_id', $issued->family_id)->orderBy('id')->lockForUpdate()->get();
        app(QuotationAccess::class)->requireRevision($fresh, $a->revision_id, [P::QUOTATION_VIEW], true, false);

        return new HandoverSubject($a, $line, $issued, $case);
    }
}
