<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Models\QuotationDisposition;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Kernel\ActiveActor;
use App\Modules\Sales\Support\Kernel\SalesActorContext;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\QuotationAcceptanceState;
use App\Modules\Sales\Support\Quotation\QuotationAccess;
use App\Modules\Sales\Support\Quotation\QuotationAvailability;
use App\Modules\Sales\Support\Quotation\QuotationOperation;
use App\Modules\Sales\Support\Quotation\QuotationRefusal;

/**
 * The seller withdraws an issued, still available, unaccepted quotation revision (QL4), with a reason. It appends one disposition and edits nothing: the issue, its snapshot and its
 * expiry stay exactly as issued. A revision that is not an issued available offer (a draft, expired, already withdrawn or replaced) is refused, and so is one on which any acceptance,
 * claim or pending consent stands — this Action never decides a customer's answer. It does not touch an Opportunity or a forecast. Guarded by `sales.quotation.withdraw` AND
 * `sales.quotation.view` (STAGED) and an active member, judged again inside the operation before any replay; exact-intent replay under a request key.
 */
final class WithdrawQuotationOffer
{
    public function __construct(private readonly QuotationOperation $operations, private readonly QuotationAcceptanceState $acceptance) {}

    /** @return array{revision_id: int, family_id: int, replayed: bool} */
    public function __invoke(User $actor, int $revisionId, ?string $requestKey, string $reason): array
    {
        $this->authorise($actor);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, 'A reason of up to 1000 characters is required to withdraw an offer.', 'reason');
        }
        $context = SalesActorContext::forUser($actor, 'quotation.withdraw');

        $outcome = $this->operations->run(
            $context, 'quotation.withdraw', $requestKey, ['revision_id' => $revisionId, 'reason' => $reason],
            fn (): QuotationRevision => app(QuotationAccess::class)->requireRevision($actor, $revisionId, [PermissionVocabulary::QUOTATION_WITHDRAW, PermissionVocabulary::QUOTATION_VIEW], true),
            function (QuotationRevision $revision) use ($actor, $reason): array {
                $this->assertWithdrawable($revision);
                QuotationDisposition::create(['family_id' => $revision->family_id, 'revision_id' => $revision->id, 'kind' => QuotationDisposition::WITHDRAWN, 'reason' => $reason, 'actor_id' => $actor->id]);

                return ['v' => 1, 'family_id' => $revision->family_id, 'revision_id' => $revision->id];
            },
        );

        return ['revision_id' => (int) $outcome['result']['revision_id'], 'family_id' => (int) $outcome['result']['family_id'], 'replayed' => $outcome['replayed']];
    }

    /** @internal Shared with the whole replacement, which withdraws availability under the same rules. */
    public function assertWithdrawable(QuotationRevision $revision): void
    {
        if (QuotationAvailability::of($revision) !== QuotationAvailability::AVAILABLE) {
            throw QuotationRefusal::make(QuotationRefusal::QUOTATION_NOT_AVAILABLE, 'Only an issued, available quotation can be withdrawn or replaced.', 'revision_id');
        }
        if ($this->acceptance->stands($revision)) {
            throw QuotationRefusal::make(QuotationRefusal::QUOTATION_NOT_AVAILABLE, 'A customer decision already stands on this quotation; it cannot be withdrawn here.', 'revision_id');
        }
    }

    private function authorise(User $actor): void
    {
        // Capability and record access are judged inside the operation by QuotationAccess, under the company access fence; this is only the early refusal of a non-member.
        ActiveActor::require($actor, 'use quotations');
    }
}
