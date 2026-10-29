<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\Company;
use App\Models\User;
use App\Modules\Numbering\Services\NumberingService;
use App\Modules\Numbering\Support\NumberingContext;
use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Models\QuotationIssue;
use App\Modules\Sales\Models\QuotationIssuedLine;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Numbering\QuotationNumbering;
use App\Modules\Sales\Support\Kernel\ActiveActor;
use App\Modules\Sales\Support\Kernel\SalesActorContext;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\Issue\IssueAssessment;
use App\Modules\Sales\Support\Quotation\Issue\QuotationIssueAssessor;
use App\Modules\Sales\Support\Quotation\QuotationIssueOutcome;
use App\Modules\Sales\Support\Quotation\QuotationOperation;
use App\Modules\Sales\Support\Quotation\QuotationRefusal;
use App\Modules\Sales\Support\SalesAccess;
use App\Support\CompanyContext;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Issues a draft quotation revision (ADR 0123, QL3): the revision becomes ISSUED with its one official number, an immutable record of exactly what was assessed, and a receipt —
 * all or nothing. The checker half of the routine maker/checker split: it needs `sales.quotation.issue` AND an independent `sales.quotation.view` (both STAGED: refused for
 * everyone until a holder recipe is reviewed), a current ACTIVE member, and it records the maker (the revision's creator) beside the issuer without forbidding them to be the
 * same person — separation here is by duty, as the accepted record has it, not by a second approval engine.
 *
 * One transaction under the revision lock: the draft must still be at the generation the issuer reviewed; the same assessment the readiness Action runs (header, every line
 * through the preview, tax, controls) must come back EMPTY under the lock — a changed draft, FX, rounding or tax evidence, an expired validity or an unevaluated mandatory control
 * refuses, never silently reprices; the Numbering engine allocates the number (policy per company: ALLOW_GAPS may leave a gap after a rollback, GAPLESS returns the counter);
 * the issue and its frozen lines are written from the assessment's own preview and tax result; the revision flips to ISSUED. The database re-checks at COMMIT that the number, the
 * lines and the totals agree. A retry with the same key replays the original number and instant before any freshness check; a different key on an issued revision is refused
 * (`QUOTATION_ALREADY_ISSUED`) and never allocates a second number. An unknown payment estimate BLOCKS (no acknowledgement path yet). No route, no PDF, no delivery.
 */
final class IssueQuotation
{
    public const POLICY_VERSION = 'ISSUE.1';

    public function __construct(
        private readonly QuotationOperation $operations,
        private readonly QuotationIssueAssessor $assessor,
        private readonly NumberingService $numbering,
        private readonly CompanyContext $company,
    ) {}

    public function __invoke(User $actor, int $revisionId, int $expectedGeneration, ?string $requestKey): QuotationIssueOutcome
    {
        $this->authorise($actor);
        $context = SalesActorContext::forUser($actor, 'quotation.issue');

        $outcome = $this->operations->run(
            $context, 'quotation.issue', $requestKey, ['revision_id' => $revisionId, 'expected_generation' => $expectedGeneration],
            // Runs inside every attempt, before the receipt is read and before any work: a revocation or suspension that lands between a retry's attempts, or between the
            // check above and the lock, refuses the replay and the mutation alike.
            function () use ($actor, $revisionId): QuotationRevision {
                $this->authorise($actor);

                return QuotationRevision::query()->whereKey($revisionId)->lockForUpdate()->first()
                ?? throw QuotationRefusal::make(QuotationRefusal::QUOTATION_NOT_FOUND, 'The quotation was not found.', 'revision_id');
            },
            fn (QuotationRevision $revision): array => $this->issue($actor, $context, $revision, $expectedGeneration),
        );

        return QuotationIssueOutcome::fromReceipt($outcome['result'], $outcome['replayed']);
    }

    private function authorise(User $actor): void
    {
        ActiveActor::require($actor, 'issue a quotation');
        if (! SalesAccess::mayAll($actor, PermissionVocabulary::QUOTATION_ISSUE, PermissionVocabulary::QUOTATION_VIEW)) {
            throw new AuthorizationException('You are not permitted to issue a quotation.');
        }
    }

    /** @return array<string, mixed> */
    private function issue(User $actor, SalesActorContext $context, QuotationRevision $revision, int $expectedGeneration): array
    {
        if ($revision->isIssued()) {
            throw QuotationRefusal::make(QuotationRefusal::QUOTATION_ALREADY_ISSUED, 'This quotation revision was already issued.', 'revision_id');
        }
        if (! $revision->isDraft()) {
            throw QuotationRefusal::make(QuotationRefusal::QUOTATION_NOT_EDITABLE, 'Only a draft quotation can be issued.', 'revision_id');
        }
        if ($revision->input_generation !== $expectedGeneration) {
            throw QuotationRefusal::make(QuotationRefusal::QUOTATION_DRAFT_STALE, "The draft is at generation {$revision->input_generation}, not {$expectedGeneration}; review it again before issuing.", 'expected_generation');
        }
        $assessment = $this->assessor->assess($actor, $context, $revision);
        if (! $assessment->ready() || $assessment->preview === null || $assessment->tax === null || ! $assessment->tax->complete || $assessment->controls === null || ! $assessment->controls->passed) {
            $codes = implode(', ', array_map(fn ($blocker): string => $blocker->code.($blocker->lineId === null ? '' : '@'.$blocker->lineId), $assessment->blockers));
            throw QuotationRefusal::make(QuotationRefusal::QUOTATION_NOT_ISSUABLE, 'The quotation cannot be issued yet'.($codes === '' ? '.' : ': '.$codes.'.'), 'blockers');
        }

        $company = Company::query()->findOrFail($this->company->id());
        $now = CarbonImmutable::parse((string) DB::selectOne('select now() as now')->now);
        $expiresAt = CarbonImmutable::parse($revision->valid_through_local_date?->format('Y-m-d') ?? $now->toDateString(), $company->timezone)->addDay()->startOfDay();
        $allocation = $this->numbering->allocate(new NumberingContext(QuotationNumbering::KEY, $now, [], [], $revision->id, null, $actor->id));

        [$rows, $totals] = $this->freeze($revision, $assessment);
        $fingerprint = hash('sha256', json_encode(['lines' => $rows, 'totals' => $totals, 'policy' => self::POLICY_VERSION], JSON_THROW_ON_ERROR));
        $issue = QuotationIssue::query()->create([
            'family_id' => $revision->family_id, 'revision_id' => $revision->id, 'reviewed_generation' => $revision->input_generation, 'revision_created_by' => $revision->created_by,
            'issued_by' => $actor->id, 'official_number' => $allocation->number, 'numbering_allocation_id' => $allocation->allocationId, 'policy_version' => self::POLICY_VERSION,
            'unknown_payment_policy' => 'BLOCK', 'currency_id' => $revision->currency_id, 'pricing_date' => $revision->pricing_date?->format('Y-m-d'),
            'tax_estimate_date' => $revision->tax_estimate_date?->format('Y-m-d'), 'valid_through_local_date' => $revision->valid_through_local_date?->format('Y-m-d'),
            'issued_timezone' => $company->timezone, 'expires_at' => $expiresAt, 'net_total' => $totals['net'], 'optional_net_total' => $totals['optional_net'], 'tax_total' => $totals['tax'],
            'gross_total' => $totals['gross'], 'rounding_policy_fingerprint' => $revision->rounding_policy_fingerprint, 'rounding_policy_version_no' => $revision->rounding_policy_version_no,
            'tax_calculation_version' => $assessment->tax->calculationVersion, 'tax_generation' => $assessment->tax->generation, 'tax_evidence_fingerprint' => $assessment->tax->evidenceFingerprint,
            'controls_outcome' => $assessment->controls->outcome, 'controls_fingerprint' => $assessment->controls->fingerprint, 'financial_fingerprint' => $fingerprint,
        ]);
        foreach ($rows as $row) {
            QuotationIssuedLine::query()->create($row + ['issue_id' => $issue->id, 'revision_id' => $revision->id, 'family_id' => $revision->family_id]);
        }
        QuotationRevision::query()->whereKey($revision->id)->update([
            'state' => 'ISSUED', 'official_number' => $allocation->number, 'numbering_allocation_id' => $allocation->allocationId, 'issued_by' => $actor->id,
            'issued_timezone' => $company->timezone, 'expires_at' => $expiresAt,
        ]);
        $revision->refresh();

        return QuotationIssueOutcome::result($revision->family_id, $revision->id, $issue->id, $allocation->number, $revision->issued_at?->toIso8601String() ?? $now->toIso8601String());
    }

    /**
     * The frozen lines and the settled document totals, from the very preview and tax result the assessment was decided on.
     *
     * @return array{0: list<array<string, mixed>>, 1: array{net: string, optional_net: string|null, tax: string, gross: string}}
     */
    private function freeze(QuotationRevision $revision, IssueAssessment $assessment): array
    {
        $tax = $assessment->tax;
        $draftLines = QuotationDraftLine::query()->where('revision_id', $revision->id)->get()->keyBy('id');
        $rows = [];
        $net = BigDecimal::zero();
        $optional = null;
        $taxTotal = BigDecimal::zero();
        foreach ($assessment->preview['lines'] ?? [] as $previewLine) {
            $line = $draftLines->get((int) $previewLine['id']);
            $lineTax = $tax?->lines[(int) $previewLine['id']] ?? null;
            if ($line === null || $lineTax === null || $line->unit_price === null) {
                throw QuotationRefusal::make(QuotationRefusal::QUOTATION_NOT_ISSUABLE, 'The quotation cannot be issued yet.', 'blockers');
            }
            $lineNet = BigDecimal::of((string) $previewLine['net']['amount'])->toScale(4);
            $counts = $line->membership === 'BASE' || ($line->membership === 'CHOOSE_ONE' && $line->is_baseline);
            if ($counts) {
                $net = $net->plus($lineNet);
                $taxTotal = $taxTotal->plus(BigDecimal::of($lineTax->tax));
            }
            if ($line->membership === 'OPTIONAL') {
                $optional = ($optional ?? BigDecimal::zero())->plus($lineNet);
            }
            $rows[] = [
                'source_line_id' => $line->id, 'logical_scope_id' => $line->logical_scope_id, 'sort_order' => $line->sort_order, 'section_title' => $line->section_title, 'description' => $line->description,
                'membership' => $line->membership, 'choice_group_id' => $line->choice_group_id, 'is_baseline' => (bool) $line->is_baseline, 'counts_in_total' => $counts, 'source_kind' => $line->source_kind,
                'item_id' => $line->item_id, 'manual_measure' => $line->manual_measure, 'offered_quantity' => (string) $line->offered_quantity, 'offered_uom_id' => $line->offered_uom_id,
                'unit_price' => (string) $line->unit_price, 'price_currency_id' => $line->price_currency_id ?? $revision->currency_id, 'fx_fingerprint' => $line->fx_fingerprint,
                'net' => (string) $lineNet, 'tax' => $lineTax->tax, 'gross' => $lineTax->gross, 'tax_disposition' => $lineTax->disposition, 'tax_evidence_reference' => $lineTax->evidenceReference,
            ];
        }

        return [$rows, ['net' => (string) $net, 'optional_net' => $optional === null ? null : (string) $optional, 'tax' => (string) $taxTotal->toScale(4), 'gross' => (string) $net->plus($taxTotal)->toScale(4)]];
    }
}
