<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Models\Opportunity;
use App\Modules\Sales\Models\QuotationChoiceGroup;
use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Models\QuotationLogicalScope;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Financial\Policy\RoundingPolicyBinding;
use App\Modules\Sales\Support\Kernel\SalesActorContext;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Pricing\PricingSourceKind;
use App\Modules\Sales\Support\Quotation\DraftChanges;
use App\Modules\Sales\Support\Quotation\DraftHeader;
use App\Modules\Sales\Support\Quotation\DraftSource;
use App\Modules\Sales\Support\Quotation\LineMembership;
use App\Modules\Sales\Support\Quotation\QuotationAssociation;
use App\Modules\Sales\Support\Quotation\QuotationDraftOutcome;
use App\Modules\Sales\Support\Quotation\QuotationMoney;
use App\Modules\Sales\Support\Quotation\QuotationOperation;
use App\Modules\Sales\Support\Quotation\QuotationRefusal;
use App\Modules\Sales\Support\SalesAccess;
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
 * and the computed base amount) — a price that cannot be converted is refused, while a non-money edit is unaffected. Only direct Item and manual lines are
 * authorised; tax is not determined here. Guarded by `sales.quotation.prepare`, a STAGED duty: until it is activated `SalesAccess` refuses every caller.
 */
final class UpdateQuotationDraft
{
    public function __construct(
        private readonly QuotationOperation $operations,
        private readonly DraftSource $sources,
        private readonly QuotationMoney $money,
    ) {}

    /** @param  array<string, mixed>  $input  header fields plus add_lines, update_lines, remove_lines */
    public function __invoke(User $actor, int $revisionId, int $expectedGeneration, ?string $requestKey, array $input): QuotationDraftOutcome
    {
        SalesAccess::require($actor, PermissionVocabulary::QUOTATION_PREPARE, 'prepare a quotation');
        $context = SalesActorContext::forUser($actor, 'quotation.update_draft');
        $changes = DraftChanges::parse($input);
        DraftHeader::verifyReferences($changes->header);

        $outcome = $this->operations->run(
            $context, 'quotation.update_draft', $requestKey, ['revision_id' => $revisionId, 'expected_generation' => $expectedGeneration] + $changes->toIntent(),
            fn (): QuotationRevision => QuotationRevision::query()->whereKey($revisionId)->lockForUpdate()->first()
                ?? throw QuotationRefusal::make(QuotationRefusal::QUOTATION_NOT_FOUND, 'The quotation was not found.', 'revision_id'),
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
        $revision->refresh();

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
            $fields['offered_uom_id'] ?? null, $revision->currency_id, $revision->recipient_party_id);
        $scope = $this->scope($revision, $actor, $fields['scope_ref'] ?? null);
        if (QuotationDraftLine::query()->where('revision_id', $revision->id)->where('logical_scope_id', $scope->id)->exists()) {
            throw QuotationRefusal::make(QuotationRefusal::SCOPE_ALREADY_ON_REVISION, 'That deliverable is already a line of this draft.', 'scope_ref');
        }
        $money = ($fields['unit_price'] ?? null) === null ? QuotationMoney::none() : $this->money->enter($revision->currency_id, $revision->pricing_date?->format('Y-m-d'), $fields['unit_price']);

        QuotationDraftLine::create([
            'family_id' => $revision->family_id, 'revision_id' => $revision->id, 'logical_scope_id' => $scope->id, 'created_by' => $actor->id,
            'sort_order' => $fields['sort_order'] ?? ((int) QuotationDraftLine::query()->where('revision_id', $revision->id)->max('sort_order') + 10),
            'section_title' => $fields['section_title'] ?? null, 'description' => $source['description'], 'membership' => $membership, 'choice_group_id' => $groupId, 'is_baseline' => $baseline,
            'source_kind' => $kind->value, 'item_id' => $fields['item_id'] ?? null, 'manual_measure' => $fields['manual_measure'] ?? null,
            'offered_quantity' => $source['offered_quantity'], 'offered_uom_id' => $source['offered_uom_id'],
        ] + $source['measures'] + $money);
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
        if (array_intersect_key($fields, array_flip(['description', 'item_id', 'manual_measure', 'offered_quantity', 'offered_uom_id'])) !== []) {
            $source = $this->sources->resolve($actor, $line->sourceKind(), $fields['item_id'] ?? $line->item_id, $fields['description'] ?? $line->description, $fields['manual_measure'] ?? $line->manual_measure,
                $fields['offered_quantity'] ?? (string) $line->offered_quantity, array_key_exists('offered_uom_id', $fields) ? $fields['offered_uom_id'] : $line->offered_uom_id,
                $revision->currency_id, $revision->recipient_party_id);
            $attributes += [
                'description' => $source['description'], 'offered_quantity' => $source['offered_quantity'], 'offered_uom_id' => $source['offered_uom_id'],
                'item_id' => $fields['item_id'] ?? $line->item_id, 'manual_measure' => $fields['manual_measure'] ?? $line->manual_measure,
            ] + $source['measures'];
        }
        if (array_key_exists('unit_price', $fields)) {
            $attributes += $fields['unit_price'] === null ? QuotationMoney::none() : $this->money->enter($revision->currency_id, $revision->pricing_date?->format('Y-m-d'), $fields['unit_price']);
        }
        $line->forceFill($attributes)->save();
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
