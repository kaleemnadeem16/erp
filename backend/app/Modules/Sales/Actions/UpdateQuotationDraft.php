<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\Currency;
use App\Models\User;
use App\Modules\Approvals\Actions\InvalidateApprovals;
use App\Modules\Sales\Models\Opportunity;
use App\Modules\Sales\Models\QuotationChoiceGroup;
use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Models\QuotationLogicalScope;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\DiscountAuthority\AuthorityParticipants;
use App\Modules\Sales\Support\Financial\Arithmetic;
use App\Modules\Sales\Support\Financial\Policy\RoundingPolicyBinding;
use App\Modules\Sales\Support\Kernel\SalesActorContext;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Pricing\PricingSourceKind;
use App\Modules\Sales\Support\Quotation\Approval\QuotationApprovalSubject;
use App\Modules\Sales\Support\Quotation\DraftChanges;
use App\Modules\Sales\Support\Quotation\DraftHeader;
use App\Modules\Sales\Support\Quotation\DraftSource;
use App\Modules\Sales\Support\Quotation\LineMembership;
use App\Modules\Sales\Support\Quotation\QuotationAccess;
use App\Modules\Sales\Support\Quotation\QuotationAdjustmentEngine;
use App\Modules\Sales\Support\Quotation\QuotationAssociation;
use App\Modules\Sales\Support\Quotation\QuotationDraftOutcome;
use App\Modules\Sales\Support\Quotation\QuotationMoney;
use App\Modules\Sales\Support\Quotation\QuotationOperation;
use App\Modules\Sales\Support\Quotation\QuotationRefusal;
use App\Modules\Sales\Support\SalesAccess;
use App\Support\Scope\CompanyAccessFence;
use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Changes a DRAFT quotation revision: header fields, and lines added, changed or removed, in one transaction. The caller states the input generation it saw
 * (optimistic concurrency); a revision that has moved since is refused as stale, and a retry of a completed request replays before that check. Saving never
 * prices, approves or adopts anything: it stores the seller's inputs. Entered unit prices are stored only with their dual-money evidence (currency, pinned FX
 * and the computed base amount) — a price that cannot be converted is refused, while a non-money edit is unaffected. Direct Item, service Item, Assembly work and manual lines are
 * authorised; tax is not determined here. Guarded by `sales.quotation.prepare`, a STAGED duty: until it is activated `SalesAccess` refuses every caller.
 */
final class UpdateQuotationDraft
{
    public function __construct(
        private readonly QuotationOperation $operations,
        private readonly DraftSource $sources,
        private readonly QuotationMoney $money,
        private readonly QuotationAdjustmentEngine $adjustments,
    ) {}

    /** @param  array<string, mixed>  $input  header fields plus add_lines, update_lines, remove_lines */
    public function __invoke(User $actor, int $revisionId, int $expectedGeneration, ?string $requestKey, array $input): QuotationDraftOutcome
    {
        return DB::transaction(function () use ($actor, $revisionId, $expectedGeneration, $requestKey, $input): QuotationDraftOutcome {
            app(CompanyAccessFence::class)->lockAll();

            return $this->update($actor, $revisionId, $expectedGeneration, $requestKey, $input);
        });
    }

    /** @param array<string, mixed> $input */
    private function update(User $actor, int $revisionId, int $expectedGeneration, ?string $requestKey, array $input): QuotationDraftOutcome
    {
        DB::transaction(fn () => app(QuotationAccess::class)->queryFor($actor, [PermissionVocabulary::QUOTATION_VIEW, PermissionVocabulary::QUOTATION_PREPARE]));
        $context = SalesActorContext::forUser($actor, 'quotation.update_draft');
        $changes = DraftChanges::parse($input);
        DraftHeader::verifyReferences($changes->header);
        // A concession is its own duty, judged by the same family policy: setting or clearing a discount needs `discount`, an override needs the sensitive `price.override`.
        $duties = [PermissionVocabulary::QUOTATION_VIEW, PermissionVocabulary::QUOTATION_PREPARE];
        if ($changes->touchesDiscount()) {
            $duties[] = PermissionVocabulary::QUOTATION_DISCOUNT;
        }
        if ($changes->touchesOverride()) {
            $duties[] = PermissionVocabulary::QUOTATION_PRICE_OVERRIDE;
        }
        if (count($duties) > 2) {
            DB::transaction(fn () => app(QuotationAccess::class)->queryFor($actor, $duties));
        }

        $outcome = $this->operations->run(
            $context, 'quotation.update_draft', $requestKey, ['revision_id' => $revisionId, 'expected_generation' => $expectedGeneration] + $changes->toIntent(),
            fn (): QuotationRevision => app(QuotationAccess::class)->requireRevision($actor, $revisionId, $duties, true),
            fn (QuotationRevision $revision): array => $this->apply($actor, $revision, $expectedGeneration, $changes),
        );

        return QuotationDraftOutcome::fromReceipt($outcome['result'], $outcome['replayed']);
    }

    /** @return array<string, mixed> */
    private function apply(User $actor, QuotationRevision $revision, int $expectedGeneration, DraftChanges $changes): array
    {
        if (! $revision->isDraft()) {
            throw QuotationRefusal::make(QuotationRefusal::QUOTATION_NOT_EDITABLE, 'Only a draft quotation can be changed.', 'revision_id');
        }
        if ($revision->input_generation !== $expectedGeneration) {
            throw QuotationRefusal::make(QuotationRefusal::QUOTATION_DRAFT_STALE, "The draft is at generation {$revision->input_generation}, not {$expectedGeneration}; reload it before changing it.", 'expected_generation');
        }
        // Every commercial editor remains a participant, even after a concession is cleared.
        foreach (array_unique([$actor->id, ...app(AuthorityParticipants::class)->ids($revision)]) as $participant) {
            DB::table('quotation_concession_participants')->insertOrIgnore(['company_id' => $revision->company_id, 'revision_id' => $revision->id, 'actor_id' => $participant]);
        }
        $header = $changes->header;
        if (($header['currency_id'] ?? $revision->currency_id) !== $revision->currency_id && $this->pricedLines($revision)->exists()) {
            throw QuotationRefusal::make(QuotationRefusal::CURRENCY_LOCKED_BY_PRICES, 'The currency cannot change while a line carries an entered price; clear the prices first.', 'currency_id');
        }
        if (($header['recipient_party_id'] ?? null) !== null) {
            QuotationAssociation::assertRecipientMatches(
                $revision->family->current_opportunity_id === null ? null : Opportunity::query()->whereKey($revision->family->current_opportunity_id)->value('party_id'),
                $header['recipient_party_id'],
            );
        }
        if ($header !== []) {
            $revision->forceFill($header)->save();
        }

        /** @var Collection<int, QuotationDraftLine> $lines */
        $lines = $revision->lines()->get()->keyBy('id');
        foreach ($changes->remove as $id) {
            ($lines[$id] ?? throw QuotationRefusal::make(QuotationRefusal::LINE_NOT_FOUND, 'The line is not on this draft.', 'remove_lines'))->delete();
        }
        // A baseline moves by clearing the old one before setting the new one, because the database allows one baseline per group at every moment.
        $baselineLast = static fn (array $a, array $b): int => (int) ($a['is_baseline'] ?? false) <=> (int) ($b['is_baseline'] ?? false);
        $update = $changes->update;
        usort($update, $baselineLast);
        foreach ($update as $fields) {
            $this->guard(fn () => $this->updateLine($actor, $revision, $lines[$fields['id']] ?? throw QuotationRefusal::make(QuotationRefusal::LINE_NOT_FOUND, 'The line is not on this draft.', 'update_lines'), $fields));
        }
        $add = $changes->add;
        usort($add, $baselineLast);
        foreach ($add as $fields) {
            $this->guard(fn () => $this->addLine($actor, $revision, $fields));
        }

        $this->refreshEvidence($revision);
        $this->applyDocumentDiscount($actor, $revision, $changes);
        $this->reassess($revision);
        $revision->refresh();
        // An approval is given for the draft exactly as it was: any edit ends a pending or granted one (history stays; the derived standing would already ignore it, ADR 0158).
        app(InvalidateApprovals::class)->forSubject(QuotationApprovalSubject::CODE, $revision->id, 'The draft was edited after approval was requested; it must be sent for approval again.');

        return QuotationDraftOutcome::result($revision->family_id, $revision->id, $revision->input_generation);
    }

    /** @param  array<string, mixed>  $fields */
    private function addLine(User $actor, QuotationRevision $revision, array $fields): void
    {
        $kind = PricingSourceKind::from($fields['source_kind']);
        $membership = $fields['membership'] ?? LineMembership::Base->value;
        $groupId = $this->choiceGroup($revision, $actor, $fields['choice_group_ref'] ?? null);
        $baseline = $fields['is_baseline'] ?? false;
        $this->assertMembership($membership, $groupId, $baseline);

        $source = $this->sources->resolve($actor, $kind, $fields['item_id'] ?? null, $fields['description'] ?? null, $fields['manual_measure'] ?? null, $fields['offered_quantity'],
            $fields['offered_uom_id'] ?? null, $revision->currency_id, $revision->recipient_party_id, $fields['assembly_version_id'] ?? null);
        $scope = $this->scope($revision, $actor, $fields['scope_ref'] ?? null);
        if (QuotationDraftLine::query()->where('revision_id', $revision->id)->where('logical_scope_id', $scope->id)->exists()) {
            throw QuotationRefusal::make(QuotationRefusal::SCOPE_ALREADY_ON_REVISION, 'That deliverable is already a line of this draft.', 'scope_ref');
        }
        $money = ($fields['unit_price'] ?? null) === null ? QuotationMoney::none() : $this->money->enter($revision->currency_id, $revision->pricing_date?->format('Y-m-d'), $fields['unit_price']);
        $concessions = $this->concessions($actor, $fields, null, $money['unit_price'] !== null);

        $declaration = $fields['tax_declaration'] ?? null;
        if ($declaration !== null && $kind !== PricingSourceKind::Manual) {
            throw $this->declarationNotAllowed();
        }
        QuotationDraftLine::create([
            'family_id' => $revision->family_id, 'revision_id' => $revision->id, 'logical_scope_id' => $scope->id, 'created_by' => $actor->id,
            'sort_order' => $fields['sort_order'] ?? ((int) QuotationDraftLine::query()->where('revision_id', $revision->id)->max('sort_order') + 10),
            'section_title' => $fields['section_title'] ?? null, 'description' => $source['description'], 'membership' => $membership, 'choice_group_id' => $groupId, 'is_baseline' => $baseline,
            'source_kind' => $kind->value, 'item_id' => $fields['item_id'] ?? null, 'assembly_version_id' => $fields['assembly_version_id'] ?? null, 'manual_measure' => $fields['manual_measure'] ?? null,
            'offered_quantity' => $source['offered_quantity'], 'offered_uom_id' => $source['offered_uom_id'],
        ] + $source['measures'] + $money + $concessions);
        if ($declaration !== null) {
            $this->declareTax($actor, $revision, $scope->id, $declaration);
        }
    }

    private function declarationNotAllowed(): \Throwable
    {
        return QuotationRefusal::make(QuotationRefusal::TAX_DECLARATION_NOT_ALLOWED, 'Only a manual line carries a seller\'s tax declaration; an Item\'s classification belongs to Tax.', 'tax_declaration');
    }

    /**
     * Appends the next version of the line's tax declaration (a correction is just the next version, with its reason). Stating exactly what is already in force adds nothing.
     *
     * @param  array{nature: string, classification: string, reason: string}  $declaration
     */
    private function declareTax(User $actor, QuotationRevision $revision, int $scopeId, array $declaration): void
    {
        $latest = DB::table('quotation_draft_tax_declarations')->where('revision_id', $revision->id)->where('logical_scope_id', $scopeId)->orderByDesc('version_no')->first();
        if ($latest !== null && $latest->nature === $declaration['nature'] && $latest->classification === $declaration['classification']) {
            return;
        }
        DB::table('quotation_draft_tax_declarations')->insert([
            'company_id' => $revision->company_id, 'revision_id' => $revision->id, 'logical_scope_id' => $scopeId, 'version_no' => $latest === null ? 1 : (int) $latest->version_no + 1,
            'nature' => $declaration['nature'], 'classification' => $declaration['classification'], 'reason' => $declaration['reason'], 'declared_by' => $actor->id,
        ]);
    }

    /** @param  array<string, mixed>  $fields */
    private function updateLine(User $actor, QuotationRevision $revision, QuotationDraftLine $line, array $fields): void
    {
        $membership = $fields['membership'] ?? $line->membership;
        $groupId = array_key_exists('choice_group_ref', $fields) ? $this->choiceGroup($revision, $actor, $fields['choice_group_ref']) : $line->choice_group_id;
        $baseline = $fields['is_baseline'] ?? $line->is_baseline;
        $this->assertMembership($membership, $groupId, $baseline);

        $attributes = ['membership' => $membership, 'choice_group_id' => $groupId, 'is_baseline' => $baseline];
        foreach (['section_title', 'sort_order'] as $plain) {
            if (array_key_exists($plain, $fields)) {
                $attributes[$plain] = $fields[$plain];
            }
        }
        if (array_intersect_key($fields, array_flip(['description', 'item_id', 'assembly_version_id', 'manual_measure', 'offered_quantity', 'offered_uom_id'])) !== []) {
            $source = $this->sources->resolve($actor, $line->sourceKind(), $fields['item_id'] ?? $line->item_id, $fields['description'] ?? $line->description, $fields['manual_measure'] ?? $line->manual_measure,
                $fields['offered_quantity'] ?? (string) $line->offered_quantity, array_key_exists('offered_uom_id', $fields) ? $fields['offered_uom_id'] : $line->offered_uom_id,
                $revision->currency_id, $revision->recipient_party_id, $fields['assembly_version_id'] ?? $line->assembly_version_id);
            $attributes += [
                'description' => $source['description'], 'offered_quantity' => $source['offered_quantity'], 'offered_uom_id' => $source['offered_uom_id'],
                'item_id' => $fields['item_id'] ?? $line->item_id, 'assembly_version_id' => $fields['assembly_version_id'] ?? $line->assembly_version_id, 'manual_measure' => $fields['manual_measure'] ?? $line->manual_measure,
            ] + $source['measures'];
        }
        if (array_key_exists('unit_price', $fields)) {
            $attributes += $fields['unit_price'] === null ? QuotationMoney::none() : $this->money->enter($revision->currency_id, $revision->pricing_date?->format('Y-m-d'), $fields['unit_price']);
        }
        $priced = array_key_exists('unit_price', $fields) ? $fields['unit_price'] !== null : $line->unit_price !== null;
        // A final-net override replaces a figure calculated from the price and quantity: changing either without restating the override would leave a stale concession.
        if ($line->net_override !== null && ! array_key_exists('net_override', $fields)
            && (array_key_exists('unit_price', $fields) || array_key_exists('offered_quantity', $fields) || array_key_exists('item_id', $fields) || array_key_exists('assembly_version_id', $fields) || array_key_exists('offered_uom_id', $fields))) {
            throw QuotationRefusal::make(QuotationRefusal::OVERRIDE_BASIS_CHANGED, 'This line has a final-net override; restate or clear the override in the same update as a price or quantity change.', 'net_override');
        }
        $attributes += $this->concessions($actor, $fields, $line, $priced);
        $declaration = $fields['tax_declaration'] ?? null;
        if ($declaration !== null && $line->sourceKind() !== PricingSourceKind::Manual) {
            throw $this->declarationNotAllowed();
        }
        $line->forceFill($attributes)->save();
        if ($declaration !== null) {
            $this->declareTax($actor, $revision, $line->logical_scope_id, $declaration);
        }
    }

    /**
     * The discount / override columns a line change sets: the entered values plus who entered them and when (set by the server, never by the caller). A discount and an
     * override exclude each other, and an override needs the unit price whose calculated net it replaces.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function concessions(User $actor, array $fields, ?QuotationDraftLine $line, bool $priced): array
    {
        $out = [];
        $now = now();
        if (array_key_exists('discount', $fields)) {
            $d = $fields['discount'];
            $out += $d === null ? ['discount_kind' => null, 'discount_value' => null, 'discount_entered_by' => null, 'discount_entered_at' => null]
                : ['discount_kind' => $d['kind'], 'discount_value' => $d['value'], 'discount_entered_by' => $actor->id, 'discount_entered_at' => $now];
        }
        if (array_key_exists('net_override', $fields)) {
            $o = $fields['net_override'];
            $out += $o === null ? ['net_override' => null, 'override_reason' => null, 'override_entered_by' => null, 'override_entered_at' => null]
                : ['net_override' => $o['amount'], 'override_reason' => $o['reason'], 'override_entered_by' => $actor->id, 'override_entered_at' => $now];
        }
        if ($line !== null && isset($out['discount_kind']) && $out['discount_kind'] === $line->discount_kind
            && BigDecimal::of((string) $out['discount_value'])->isEqualTo((string) $line->discount_value)) {
            $out['discount_entered_by'] = $line->discount_entered_by;
            $out['discount_entered_at'] = $line->discount_entered_at;
        }
        if ($line !== null && isset($out['net_override']) && $line->net_override !== null
            && BigDecimal::of((string) $out['net_override'])->isEqualTo((string) $line->net_override)) {
            $out['override_entered_by'] = $line->override_entered_by;
            $out['override_entered_at'] = $line->override_entered_at;
        }
        $discount = array_key_exists('discount_kind', $out) ? $out['discount_kind'] : $line?->discount_kind;
        $override = array_key_exists('net_override', $out) ? $out['net_override'] : $line?->net_override;
        if ($discount !== null && $override !== null) {
            throw QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, 'A line takes a discount or a final-net override, not both: an override runs no discount afterwards.', 'net_override');
        }
        if ($discount === null && $override === null && ($line?->approval_reason !== null || $line?->approval_required)) {
            // Nothing is conceded any more, so there is nothing left that could need approval.
            $out += ['approval_required' => false, 'approval_reason' => null];
        }
        if ($override !== null && ! $priced) {
            throw QuotationRefusal::make(QuotationRefusal::OVERRIDE_NOT_ALLOWED, 'A final-net override needs an entered unit price: the calculated net it replaces must exist.', 'net_override');
        }

        return $out;
    }

    /** A document discount is one slot per draft: set it (stamped with the actor and time) or remove it. */
    private function applyDocumentDiscount(User $actor, QuotationRevision $revision, DraftChanges $changes): void
    {
        if (! $changes->documentDiscountSet) {
            return;
        }
        $existing = DB::table('quotation_draft_document_discounts')->where('revision_id', $revision->id);
        if ($changes->documentDiscount === null) {
            $existing->delete();

            return;
        }
        $values = ['kind' => $changes->documentDiscount['kind'], 'value' => $changes->documentDiscount['value'], 'entered_by' => $actor->id, 'entered_at' => now(), 'approval_required' => false, 'approval_reason' => null];
        $previous = $existing->first();
        if ($previous !== null && $previous->kind === $values['kind'] && BigDecimal::of((string) $previous->value)->isEqualTo($values['value'])) {
            $values['entered_by'] = $previous->entered_by;
            $values['entered_at'] = $previous->entered_at;
        }
        if ($previous !== null) {
            DB::table('quotation_draft_document_discounts')->where('revision_id', $revision->id)->update($values);
        } else {
            DB::table('quotation_draft_document_discounts')->insert($values + ['company_id' => $revision->company_id, 'revision_id' => $revision->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /**
     * After every change: prove each concession computes (a discount larger than its line, an override above the calculated net or one that is not a whole currency unit is
     * refused now, not at issue) and record whether it needs approval. A concession whose figures cannot be computed yet (no price, no pricing date) is recorded as "not
     * proven within a limit".
     */
    private function reassess(QuotationRevision $revision): void
    {
        $document = DB::table('quotation_draft_document_discounts')->where('revision_id', $revision->id)->first();
        $lines = QuotationDraftLine::query()->where('revision_id', $revision->id)->get();
        $date = $revision->pricing_date?->format('Y-m-d');
        $policy = $date === null ? null : $this->money->policyOn($date);
        $currency = Currency::query()->findOrFail($revision->currency_id);
        $rule = $policy?->settlementRule($currency);
        $counted = [];
        foreach ($lines as $line) {
            $concession = $line->discount_kind !== null || $line->net_override !== null;
            $figures = null;
            $quantity = (string) $line->offered_quantity;
            if ($line->unit_price !== null && $policy !== null && $rule !== null) {
                $figures = $this->adjustments->line($line, $revision->currency_id, $date, $policy, $rule, Arithmetic::quantity($quantity));
            }
            if ($figures !== null && ($line->membership === 'BASE' || ($line->membership === 'CHOOSE_ONE' && $line->is_baseline))) {
                $counted[$line->id] = $figures['net'];
            }
            $reason = $concession ? $this->adjustments->approvalFor($line->net_override !== null, $line->discount_kind !== null, $figures, $revision->currency_id, $date, $policy, $quantity) : null;
            if ($line->approval_reason !== $reason) {
                $line->forceFill(['approval_required' => $reason !== null, 'approval_reason' => $reason])->save();
            }
        }
        if ($document === null) {
            return;
        }
        $reason = QuotationAdjustmentEngine::APPROVAL_UNVERIFIED;
        $counts = collect($lines)->filter(fn ($l): bool => $l->membership === 'BASE' || ($l->membership === 'CHOOSE_ONE' && $l->is_baseline))->count();
        if ($policy !== null && $rule !== null && $counts > 0 && count($counted) === $counts) {
            $result = $this->adjustments->document($counted, (string) $document->kind, (string) $document->value, $rule);
            $before = array_reduce($counted, fn ($carry, $net) => $carry->plus($net), BigDecimal::zero());
            $reason = $this->adjustments->approvalForDocument($before, $before->minus($result['amount']), (string) $document->kind, (string) $document->value, $revision->currency_id, $date, $policy);
        }
        DB::table('quotation_draft_document_discounts')->where('revision_id', $revision->id)->update(['approval_required' => $reason !== null, 'approval_reason' => $reason]);
    }

    /**
     * Entered prices carry evidence for one pricing date and one rounding policy. When the pricing date moved, each priced line is converted again for the new
     * date (a price that no longer converts or no longer fits the policy refuses the whole update), and the revision remembers the policy its prices were prepared under.
     */
    private function refreshEvidence(QuotationRevision $revision): void
    {
        $date = $revision->pricing_date?->format('Y-m-d');
        foreach ($this->pricedLines($revision)->get() as $line) {
            if (($line->fx_evidence['requested_date'] ?? null) !== $date) {
                $line->forceFill($this->money->enter($revision->currency_id, $date, (string) $line->unit_price))->save();
            }
        }
        if ($date !== null && $this->pricedLines($revision)->exists()) {
            $binding = RoundingPolicyBinding::capture($this->money->policyOn($date), $date);
            $revision->forceFill(['rounding_policy_fingerprint' => $binding->fingerprint, 'rounding_policy_version_no' => $binding->versionNo, 'rounding_policy_as_of' => $binding->asOfDate])->save();
        } else {
            $revision->forceFill(['rounding_policy_fingerprint' => null, 'rounding_policy_version_no' => null, 'rounding_policy_as_of' => null])->save();
        }
    }

    /** @return Builder<QuotationDraftLine> */
    private function pricedLines(QuotationRevision $revision): Builder
    {
        return QuotationDraftLine::query()->where('revision_id', $revision->id)->whereNotNull('unit_price');
    }

    private function assertMembership(string $membership, ?int $groupId, bool $baseline): void
    {
        if (($membership === LineMembership::ChooseOne->value) !== ($groupId !== null)) {
            throw QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, 'A choose-one line names its choice group, and no other line does.', 'choice_group_ref');
        }
        if ($baseline && $membership !== LineMembership::ChooseOne->value) {
            throw QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, 'Only a choose-one line can be the baseline of its group.', 'is_baseline');
        }
    }

    private function scope(QuotationRevision $revision, User $actor, ?string $ref): QuotationLogicalScope
    {
        $ref ??= 'line-'.Str::lower((string) Str::ulid());

        return QuotationLogicalScope::query()->where('family_id', $revision->family_id)->where('intent_ref', $ref)->first()
            ?? QuotationLogicalScope::create(['family_id' => $revision->family_id, 'intent_ref' => $ref, 'created_by' => $actor->id])->refresh();
    }

    private function choiceGroup(QuotationRevision $revision, User $actor, ?string $ref): ?int
    {
        if ($ref === null) {
            return null;
        }

        return (QuotationChoiceGroup::query()->where('family_id', $revision->family_id)->where('intent_ref', $ref)->first()
            ?? QuotationChoiceGroup::create(['family_id' => $revision->family_id, 'intent_ref' => $ref, 'created_by' => $actor->id]))->id;
    }

    /** A savepoint around one line write, so a database refusal can be reported as a business refusal without aborting the surrounding transaction. */
    private function guard(Closure $write): void
    {
        try {
            DB::transaction(function () use ($write): void {
                $write();
            });
        } catch (UniqueConstraintViolationException $violation) {
            if (str_contains($violation->getMessage(), 'quotation_draft_lines_one_baseline')) {
                throw QuotationRefusal::make(QuotationRefusal::CHOICE_BASELINE_CONFLICT, 'A choice group has exactly one baseline; clear the current one in the same update.', 'is_baseline');
            }
            if (str_contains($violation->getMessage(), 'quotation_draft_lines_scope_unique')) {
                throw QuotationRefusal::make(QuotationRefusal::SCOPE_ALREADY_ON_REVISION, 'That deliverable is already a line of this draft.', 'scope_ref');
            }

            throw $violation;
        }
    }
}
