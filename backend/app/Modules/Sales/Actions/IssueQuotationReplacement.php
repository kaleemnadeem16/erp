<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Models\QuotationDisposition;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Kernel\ActiveActor;
use App\Modules\Sales\Support\Kernel\SalesActorContext;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\QuotationIssueOutcome;
use App\Modules\Sales\Support\Quotation\QuotationOperation;
use App\Modules\Sales\Support\Quotation\QuotationRefusal;
use App\Modules\Sales\Support\SalesAccess;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Issues a successor draft as the WHOLE replacement of its issued, available, unaccepted predecessor (QL5), in one transaction: the successor is issued exactly as `IssueQuotation`
 * issues (new number, frozen record, customer original), and only then is the predecessor's availability removed, by one REPLACED disposition naming it. If anything fails the
 * predecessor stays as available as before and nothing is issued; a retry under the same key replays and does nothing twice. Both revisions are locked in id order, so a concurrent
 * withdrawal, replacement or issue serialises and the loser is refused. Needs `sales.quotation.issue`, `sales.quotation.withdraw` and `sales.quotation.view` (all STAGED) and an active
 * member, judged again inside the operation. Partial replacement does not exist: a standing acceptance, claim or pending consent refuses it.
 */
final class IssueQuotationReplacement
{
    public function __construct(private readonly QuotationOperation $operations, private readonly IssueQuotation $issuer, private readonly WithdrawQuotationOffer $withdrawals) {}

    public function __invoke(User $actor, int $predecessorRevisionId, int $revisionId, int $expectedGeneration, ?string $requestKey, string $reason): QuotationIssueOutcome
    {
        $this->authorise($actor);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, 'A reason of up to 1000 characters is required to replace an offer.', 'reason');
        }
        $context = SalesActorContext::forUser($actor, 'quotation.issue_replacement');

        $outcome = $this->operations->run(
            $context, 'quotation.issue_replacement', $requestKey,
            ['predecessor_revision_id' => $predecessorRevisionId, 'revision_id' => $revisionId, 'expected_generation' => $expectedGeneration, 'reason' => $reason],
            function () use ($actor, $predecessorRevisionId, $revisionId): QuotationRevision {
                $this->authorise($actor);
                // Both rows, in id order, so two replacements, a withdrawal and an issue can never wait on each other in a cycle.
                $locked = QuotationRevision::query()->whereIn('id', [$predecessorRevisionId, $revisionId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');

                return $locked->get($revisionId) ?? throw QuotationRefusal::make(QuotationRefusal::QUOTATION_NOT_FOUND, 'The quotation was not found.', 'revision_id');
            },
            function (QuotationRevision $revision) use ($actor, $context, $predecessorRevisionId, $expectedGeneration, $reason): array {
                $predecessor = QuotationRevision::query()->find($predecessorRevisionId);
                if ($predecessor === null || $revision->predecessor_revision_id !== $predecessor->id || $predecessor->family_id !== $revision->family_id) {
                    throw QuotationRefusal::make(QuotationRefusal::QUOTATION_PREDECESSOR_INVALID, 'The draft does not succeed that issued quotation.', 'predecessor_revision_id');
                }
                $this->withdrawals->assertWithdrawable($predecessor);
                $result = $this->issuer->issueLocked($actor, $context, $revision, $expectedGeneration);
                QuotationDisposition::create([
                    'family_id' => $revision->family_id, 'revision_id' => $predecessor->id, 'kind' => QuotationDisposition::REPLACED, 'reason' => $reason,
                    'replacement_revision_id' => $revision->id, 'actor_id' => $actor->id,
                ]);

                return $result + ['replaced_revision_id' => $predecessor->id];
            },
        );

        return QuotationIssueOutcome::fromReceipt($outcome['result'], $outcome['replayed']);
    }

    private function authorise(User $actor): void
    {
        ActiveActor::require($actor, 'replace a quotation');
        if (! SalesAccess::mayAll($actor, PermissionVocabulary::QUOTATION_ISSUE, PermissionVocabulary::QUOTATION_WITHDRAW, PermissionVocabulary::QUOTATION_VIEW)) {
            throw new AuthorizationException('You are not permitted to replace a quotation.');
        }
    }
}
