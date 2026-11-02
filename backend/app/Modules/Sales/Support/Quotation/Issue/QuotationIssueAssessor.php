<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation\Issue;

use App\Models\Company;
use App\Models\User;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Actions\PreviewQuotation;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Financial\Certainty;
use App\Modules\Sales\Support\Kernel\SalesActorContext;
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

        $unique = [];
        foreach ($blockers as $blocker) {
            $unique[$blocker->code.'|'.$blocker->scope.'|'.$blocker->lineId] = $blocker;
        }

        return new IssueAssessment(array_values($unique), $preview, $tax, $controls);
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
            if (($line['fx']['current'] ?? 'SAME') !== 'SAME') {
                $blockers[] = new IssueBlocker('FX_EVIDENCE_'.$line['fx']['current'], IssueBlocker::LINE, $id);
            }
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
