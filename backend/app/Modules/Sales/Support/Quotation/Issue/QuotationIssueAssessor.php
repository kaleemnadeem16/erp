<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation\Issue;

use App\Models\Company;
use App\Models\User;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Actions\PreviewQuotation;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Financial\Certainty;
use App\Modules\Sales\Support\Kernel\SalesActorContext;
use App\Modules\Sales\Support\Quotation\Approval\QuotationApprovals;
use App\Support\CompanyContext;
use App\Support\Status;

/**
 * Decides, from the facts of a draft alone, whether it could be issued: the header, every line through the same PR0/FR4 preview the preparer sees, and the two independent
 * evidence providers (tax, controls). It checks no duty — each caller (the readiness Action, `IssueQuotation`) carries its own guard — and writes nothing. The assessment keeps
 * the preview, tax and controls results it was decided on, so what the issuer freezes is exactly what was assessed. Unknown is never a pass.
 *
 * @internal
 */
final class QuotationIssueAssessor
{
    public function __construct(
        private readonly PreviewQuotation $preview,
        private readonly QuotationTaxProvider $tax,
        private readonly QuotationControlsProvider $controls,
        private readonly CompanyContext $company,
    ) {}

    public function assess(User $actor, SalesActorContext $context, QuotationRevision $revision): IssueAssessment
    {
        $blockers = $this->header($revision);
        $preview = null;
        if ($revision->lines()->doesntExist()) {
            $blockers[] = new IssueBlocker('NO_LINES', IssueBlocker::HEADER);
        } elseif ($revision->recipient_party_id !== null && $revision->pricing_date !== null && ! $this->recipientBlocked($revision)) {
            $preview = $this->preview->forRevision($actor, $revision);
            array_push($blockers, ...$this->lines($preview));
        }
        $nets = $this->nets($revision, $preview);
        $tax = $this->tax->assess($context, $revision, $nets);
        $controls = $this->controls->assess($context, $revision, $nets);
        array_push($blockers, ...$tax->blockers, ...$controls->blockers);

        [$blockers, $approval] = $this->applyApproval($revision, $blockers);

        $unique = [];
        foreach ($blockers as $blocker) {
            $unique[$blocker->code.'|'.$blocker->scope.'|'.$blocker->lineId] = $blocker;
        }

        return new IssueAssessment(array_values($unique), $preview, $tax, $controls, $approval?->id, $approval === null ? null : (string) ($approval->facts['facts']['content_fingerprint'] ?? ''));
    }

    /**
     * A concession that needs approval (the recorded `APPROVAL_REQUIRED` blockers) is judged against the revision's approval standing (ADR 0158): approved for exactly this draft
     * lifts them and names the approval the issue will freeze; pending or rejected renames them so the screen can say which; anything else leaves them as they are. Nothing
     * else is lifted - controls, tax, FX and rounding blockers are independent.
     *
     * @param  list<IssueBlocker>  $blockers
     * @return array{0: list<IssueBlocker>, 1: ApprovalRequest|null}
     */
    private function applyApproval(QuotationRevision $revision, array $blockers): array
    {
        $needed = array_filter($blockers, fn (IssueBlocker $blocker): bool => $blocker->code === 'APPROVAL_REQUIRED');
        if ($needed === []) {
            return [$blockers, null];
        }
        $standing = app(QuotationApprovals::class)->standing($revision);
        $code = match ($standing['state']) {
            QuotationApprovals::APPROVED => null,
            QuotationApprovals::PENDING => 'APPROVAL_PENDING',
            QuotationApprovals::REJECTED => 'APPROVAL_REJECTED',
            default => 'APPROVAL_REQUIRED',
        };
        $result = [];
        foreach ($blockers as $blocker) {
            if ($blocker->code !== 'APPROVAL_REQUIRED') {
                $result[] = $blocker;
            } elseif ($code !== null) {
                $result[] = new IssueBlocker($code, $blocker->scope, $blocker->lineId);
            }
        }

        return [$result, $standing['state'] === QuotationApprovals::APPROVED ? $standing['request'] : null];
    }

    /** @return list<IssueBlocker> */
    private function header(QuotationRevision $revision): array
    {
        $blockers = [];
        if ($revision->recipient_party_id === null) {
            $blockers[] = new IssueBlocker('RECIPIENT_REQUIRED', IssueBlocker::HEADER);
        } elseif ($this->recipientBlocked($revision)) {
            $blockers[] = new IssueBlocker('RECIPIENT_NOT_ACTIVE', IssueBlocker::HEADER);
        }
        if ($revision->pricing_date === null) {
            $blockers[] = new IssueBlocker('PRICING_DATE_REQUIRED', IssueBlocker::HEADER);
        }
        if ($revision->valid_through_local_date === null) {
            $blockers[] = new IssueBlocker('VALID_THROUGH_REQUIRED', IssueBlocker::HEADER);
        } elseif ($revision->valid_through_local_date->format('Y-m-d') < now(Company::query()->findOrFail($this->company->id())->timezone)->format('Y-m-d')) {
            $blockers[] = new IssueBlocker('VALID_THROUGH_PAST', IssueBlocker::HEADER);
        }

        return $blockers;
    }

    private function recipientBlocked(QuotationRevision $revision): bool
    {
        return $revision->recipient_party_id !== null
            && Party::query()->whereKey($revision->recipient_party_id)->where('status', Status::Active->value)->doesntExist();
    }

    /**
     * @param  array<string, mixed>  $preview
     * @return list<IssueBlocker>
     */
    private function lines(array $preview): array
    {
        $blockers = [];
        foreach ($preview['lines'] as $line) {
            $id = (int) $line['id'];
            foreach ($line['issues'] as $code) {
                $blockers[] = new IssueBlocker((string) $code, IssueBlocker::LINE, $id);
            }
            if ($line['net']['certainty'] !== Certainty::Complete->value) {
                $blockers[] = new IssueBlocker((string) ($line['net']['reasons'][0] ?? 'NET_UNKNOWN'), IssueBlocker::LINE, $id);
            }
            if (($line['adjustment']['approval_required'] ?? false) === true) {
                // Recorded here, decided by `applyApproval`: a concession that needs approval keeps the draft from being issued until an approval for exactly this draft stands.
                $blockers[] = new IssueBlocker('APPROVAL_REQUIRED', IssueBlocker::LINE, $id);
            }
            if (($line['fx']['current'] ?? 'SAME') !== 'SAME') {
                $blockers[] = new IssueBlocker('FX_EVIDENCE_'.$line['fx']['current'], IssueBlocker::LINE, $id);
            }
        }
        if (($preview['document_discount']['approval_required'] ?? false) === true) {
            $blockers[] = new IssueBlocker('APPROVAL_REQUIRED', IssueBlocker::HEADER);
        }
        foreach ($preview['document_discount']['issues'] ?? [] as $code) {
            $blockers[] = new IssueBlocker((string) $code, IssueBlocker::HEADER);
        }
        foreach ($preview['issues'] as $issue) {
            $blockers[] = new IssueBlocker(explode(':', (string) $issue)[0], IssueBlocker::HEADER);
        }
        if (($preview['revalidation']['requires_review'] ?? false) === true) {
            $blockers[] = new IssueBlocker('ROUNDING_POLICY_CHANGED', IssueBlocker::HEADER);
            foreach ($preview['revalidation']['non_conforming_lines'] as $lineId) {
                $blockers[] = new IssueBlocker('UNIT_PRICE_PRECISION_EXCEEDED', IssueBlocker::LINE, (int) $lineId);
            }
        }

        return $blockers;
    }

    /**
     * @param  array<string, mixed>|null  $preview
     * @return array<int, string|null> line id => settled net, or null while the net is not known
     */
    private function nets(QuotationRevision $revision, ?array $preview): array
    {
        if ($preview === null) {
            return $revision->lines()->pluck('id')->mapWithKeys(fn ($id): array => [(int) $id => null])->all();
        }
        $nets = [];
        foreach ($preview['lines'] as $line) {
            $nets[(int) $line['id']] = $line['net']['certainty'] === Certainty::Complete->value ? (string) $line['net']['amount'] : null;
        }

        return $nets;
    }
}
