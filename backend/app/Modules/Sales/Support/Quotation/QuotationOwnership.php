<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation;

use App\Models\User;
use App\Modules\Administration\Models\OrganisationUnit;
use App\Modules\Administration\Support\MemberStatus;
use App\Modules\Sales\Models\QuotationFamily;
use App\Modules\Sales\Models\QuotationOwnershipEvent;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\SalesAccess;
use App\Modules\Sales\Support\SalesRecords;
use App\Support\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The one writer of quotation ownership: the locked family projection and its event are always written together, so the database's commit-time check (the family
 * equals the end of its chain) holds. Authority is a currently active member of this company holding the dedicated STAGED duty AND an independent view of quotations, each judged by its own scope through
 * `SalesAccess` (which today accepts only an unrestricted grant and refuses every caller while the duties are staged). Ownership is responsibility, not access:
 * naming an owner grants that person nothing, and no unit is inferred or read for visibility.
 */
final class QuotationOwnership
{
    public const REASON_MAX = 1000;

    public const REFERENCE_MAX = 500;

    /** Checked before the family is looked up, so capability absence is the same refusal whether or not the family exists. */
    public static function requireAuthority(?User $actor): void
    {
        // SalesAccess judges capabilities and scope, not membership: an actor who has since been suspended (or never belonged to this company) must not change ownership,
        // and the object in hand may be stale, so the member is read again, in this company, before anything else is asked.
        $current = $actor === null ? null : User::query()->where('company_id', app(CompanyContext::class)->id())->whereKey($actor->id)->first();
        if ($current === null || $current->status !== MemberStatus::Active) {
            throw new AuthorizationException('You are not permitted to change who is responsible for a quotation.');
        }
        if (! SalesAccess::mayAll($actor, PermissionVocabulary::QUOTATION_ASSIGN, PermissionVocabulary::QUOTATION_VIEW)) {
            throw new AuthorizationException('You are not permitted to change who is responsible for a quotation.');
        }
    }

    public static function lockFamily(int $familyId): QuotationFamily
    {
        return QuotationFamily::query()->whereKey($familyId)->lockForUpdate()->first()
            ?? throw QuotationRefusal::make(QuotationRefusal::QUOTATION_NOT_FOUND, 'The quotation was not found.', 'family_id');
    }

    public static function requireResolved(QuotationFamily $family): void
    {
        if ($family->access_baseline_state === 'UNRESOLVED') {
            throw QuotationRefusal::make(QuotationRefusal::QUOTATION_OWNERSHIP_UNRESOLVED, 'This quotation has no recorded owner yet; it needs an approved baseline capture first.', 'family_id');
        }
    }

    public static function requireGenerations(QuotationFamily $family, int $expectedOwnership, int $expectedAccess): void
    {
        if ($family->ownership_generation !== $expectedOwnership || $family->access_generation !== $expectedAccess) {
            throw QuotationRefusal::make(QuotationRefusal::QUOTATION_OWNERSHIP_STALE,
                "The quotation is at ownership generation {$family->ownership_generation} and access generation {$family->access_generation}, not {$expectedOwnership} and {$expectedAccess}; reload it before changing it.",
                'expected_ownership_generation');
        }
    }

    public static function reason(string $reason, string $field = 'reason'): string
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX) {
            throw QuotationRefusal::make(QuotationRefusal::REASON_REQUIRED, 'A reason of at most '.self::REASON_MAX.' characters is required.', $field);
        }

        return $reason;
    }

    public static function reference(string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > self::REFERENCE_MAX) {
            throw QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, 'A reference of at most '.self::REFERENCE_MAX.' characters is required.', $field);
        }

        return $value;
    }

    /** An active member of the company; a suspended one stays valid history but cannot be made responsible. */
    public static function eligibleOwner(int $userId, string $field = 'owner_id'): User
    {
        return SalesRecords::eligibleMember($userId, $field);
    }

    /** An active unit of the company. */
    public static function activeUnit(int $unitId, string $field = 'access_unit_id'): OrganisationUnit
    {
        $unit = OrganisationUnit::query()->whereKey($unitId)->where('status', OrganisationUnit::ACTIVE)->first();
        if ($unit === null) {
            throw QuotationRefusal::make(QuotationRefusal::ACCESS_UNIT_NOT_FOUND, 'That organisation unit does not exist in this company, or is archived.', $field);
        }

        return $unit;
    }

    /**
     * Appends one event and moves the locked family to it. The caller holds the family lock and has checked authority, state and generations.
     *
     * @param  array{source?: string, approval?: string}  $references  CAPTURED_BASELINE only
     */
    public static function append(QuotationFamily $family, string $kind, int $newOwnerId, ?int $newUnitId, User $actor, string $reason, array $references = []): QuotationOwnershipEvent
    {
        $previous = $family->ownershipEvents()->reorder('generation', 'desc')->first();
        $generation = $family->ownership_generation + 1;
        $accessGeneration = match ($kind) {
            QuotationOwnershipEvent::REASSIGNED => $family->access_generation,
            QuotationOwnershipEvent::UNIT_CHANGED => $family->access_generation + 1,
            QuotationOwnershipEvent::CAPTURED_BASELINE => 1,
            default => throw QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, 'Unknown ownership event.', 'kind'),
        };

        $event = QuotationOwnershipEvent::create([
            'family_id' => $family->id, 'generation' => $generation, 'previous_event_id' => $previous?->id, 'kind' => $kind,
            'old_owner_id' => $family->responsible_owner_id, 'new_owner_id' => $newOwnerId, 'old_unit_id' => $family->access_unit_id, 'new_unit_id' => $newUnitId,
            'access_generation' => $accessGeneration, 'baseline_source_reference' => $references['source'] ?? null, 'approval_reference' => $references['approval'] ?? null,
            'actor_id' => $actor->id, 'reason' => $reason, 'recorded_at' => now(),
        ]);
        $family->forceFill([
            'responsible_owner_id' => $newOwnerId, 'access_unit_id' => $newUnitId, 'ownership_generation' => $generation, 'access_generation' => $accessGeneration,
            'access_baseline_state' => $kind === QuotationOwnershipEvent::CAPTURED_BASELINE ? 'CAPTURED' : $family->access_baseline_state,
        ])->save();

        return $event;
    }
}
