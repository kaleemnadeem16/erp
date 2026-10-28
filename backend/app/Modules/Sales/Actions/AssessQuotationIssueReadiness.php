<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Financial\Certainty;
use App\Modules\Sales\Support\Kernel\SalesActorContext;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\Issue\ControlsIssueCheck;
use App\Modules\Sales\Support\Quotation\Issue\IssueBlocker;
use App\Modules\Sales\Support\Quotation\Issue\TaxIssueCheck;
use App\Modules\Sales\Support\Quotation\QuotationRefusal;
use App\Modules\Sales\Support\SalesAccess;
use App\Support\CompanyContext;
use App\Support\Status;

/**
 * Whether a draft could be issued today, as a list of named blockers (ADR 0123, QL3). Read-only: it allocates no number, writes nothing, and answers for the preparer
 * (`sales.quotation.prepare`, STAGED) with codes only — no amount, cost, rate or free text. It never says "ready" for what it did not check: the header facts, every line
 * through the same PR0/FR4 preview the preparer already sees (price entered, net complete, FX evidence still the same, rounding policy still the one the prices were
 * prepared under), and then the two independent evidence checks — tax (FR3) and mandatory controls (FR4) — whose real implementations plug in by rebinding their
 * interfaces. Until they do, they report TAX_NOT_DETERMINED and CONTROLS_NOT_CONFIGURED; unknown is never a pass.
 */
final class AssessQuotationIssueReadiness
{
    public function __construct(
        private readonly PreviewQuotation $preview,
        private readonly TaxIssueCheck $tax,
        private readonly ControlsIssueCheck $controls,
        private readonly CompanyContext $company,
    ) {}

    /** @return array{revision_id: int, input_generation: int, issue_ready: bool, blockers: list<array{code: string, scope: string, line_id: int|null}>} */
    public function __invoke(User $actor, int $revisionId): array
    {
        SalesAccess::require($actor, PermissionVocabulary::QUOTATION_PREPARE, 'assess a quotation for issue');
        $context = SalesActorContext::forUser($actor, 'quotation.assess_issue_readiness');
        $revision = QuotationRevision::query()->find($revisionId)
            ?? throw QuotationRefusal::make(QuotationRefusal::QUOTATION_NOT_FOUND, 'The quotation was not found.', 'revision_id');
        if (! $revision->isDraft()) {
            throw QuotationRefusal::make(QuotationRefusal::QUOTATION_NOT_EDITABLE, 'Only a draft quotation can be assessed for issue.', 'revision_id');
        }

        $blockers = $this->header($revision);
        if ($revision->lines()->doesntExist()) {
            $blockers[] = new IssueBlocker('NO_LINES', IssueBlocker::HEADER);
        } elseif ($revision->recipient_party_id !== null && $revision->pricing_date !== null && ! $this->recipientBlocked($revision)) {
            array_push($blockers, ...$this->lines($actor, $revision));
        }
        array_push($blockers, ...$this->tax->blockers($context, $revision), ...$this->controls->blockers($context, $revision));
        if (! class_exists(IssueQuotation::class)) {
            // Nothing is ready to issue until the issuing Action exists; this blocker disappears by itself when it is delivered.
            $blockers[] = new IssueBlocker('ISSUE_NOT_AVAILABLE', IssueBlocker::AUTHORITY);
        }

        $unique = [];
        foreach ($blockers as $blocker) {
            $unique[$blocker->code.'|'.$blocker->scope.'|'.$blocker->lineId] = $blocker->toArray();
        }

        return ['revision_id' => $revision->id, 'input_generation' => $revision->input_generation, 'issue_ready' => $unique === [], 'blockers' => array_values($unique)];
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

    /** @return list<IssueBlocker> */
    private function lines(User $actor, QuotationRevision $revision): array
    {
        $preview = ($this->preview)($actor, $revision->id);
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
}
