<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions\Access;

use App\Models\User;
use App\Modules\Sales\Models\QuotationFamily;
use App\Modules\Sales\Support\Kernel\ActiveActor;
use App\Modules\Sales\Support\PermissionVocabulary as Duty;
use App\Modules\Sales\Support\Quotation\QuotationAccess;
use App\Modules\Sales\Support\Quotation\QuotationFamilyScopeAdapter;
use App\Modules\Sales\Support\Quotation\QuotationRefusal;
use App\Support\Scope\RecordScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** Safe family metadata; administrative history is gated independently and never projects raw reasons or authority pins. */
final class ReadQuotationFamilyAccess
{
    /** @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function __invoke(User $actor, int $familyId, array $filters = []): array
    {
        return DB::transaction(function () use ($actor, $familyId, $filters): array {
            $family = app(QuotationAccess::class)->requireFamily($actor, $familyId, [Duty::QUOTATION_VIEW]);
            $pageSize = filter_var($filters['history_per_page'] ?? 25, FILTER_VALIDATE_INT);
            $ownershipBefore = isset($filters['ownership_before']) ? filter_var($filters['ownership_before'], FILTER_VALIDATE_INT) : null;
            $sharesBefore = isset($filters['shares_before']) ? filter_var($filters['shares_before'], FILTER_VALIDATE_INT) : null;
            if ($pageSize === false || $pageSize < 1 || $pageSize > 50 || ($ownershipBefore !== null && ($ownershipBefore === false || $ownershipBefore < 1))
                || ($sharesBefore !== null && ($sharesBefore === false || $sharesBefore < 1)) || array_diff(array_keys($filters), ['history_per_page', 'ownership_before', 'shares_before']) !== []) {
                throw QuotationRefusal::make(QuotationRefusal::INVALID_INPUT, 'Use supported history cursors and a bounded page size.');
            }
            $scope = app(RecordScope::class)->resolveForResource(ActiveActor::require($actor), Duty::QUOTATION_VIEW, QuotationFamilyScopeAdapter::RESOURCE);
            $path = $scope->isAll() ? 'ALL' : ($scope->ownsRecords() && $family->responsible_owner_id === $actor->id ? 'OWN'
                : ($family->access_unit_id !== null && in_array($family->access_unit_id, $scope->unitIds(), true) ? 'MY_UNITS' : 'DELEGATED'));
            if ($path === 'DELEGATED' && ! $scope->allowsNamedDelegation()) {
                throw new AuthorizationException('You are not permitted to access quotations.');
            }
            $resolved = $family->access_baseline_state !== 'UNRESOLVED';
            $rights = [
                'prepare' => $this->may($actor, $family, [Duty::QUOTATION_VIEW, Duty::QUOTATION_PREPARE], true),
                'assign' => $resolved && $this->may($actor, $family, [Duty::QUOTATION_VIEW, Duty::QUOTATION_ASSIGN]),
                'delegate_view' => $resolved && $this->may($actor, $family, [Duty::QUOTATION_VIEW, Duty::QUOTATION_ACCESS_DELEGATE]),
                'delegate_prepare' => $resolved && $this->may($actor, $family, [Duty::QUOTATION_VIEW, Duty::QUOTATION_PREPARE, Duty::QUOTATION_ACCESS_DELEGATE]),
                'revoke' => $resolved && $this->may($actor, $family, [Duty::QUOTATION_VIEW, Duty::QUOTATION_ACCESS_REVOKE]),
            ];
            if (($ownershipBefore !== null && ! $rights['assign']) || ($sharesBefore !== null && ! $rights['delegate_view'] && ! $rights['revoke'])
                || (array_key_exists('history_per_page', $filters) && ! $rights['assign'] && ! $rights['delegate_view'] && ! $rights['revoke'])) {
                throw new AuthorizationException('You are not permitted to access this history.');
            }
            $owner = $family->responsible_owner_id === null ? null : DB::table('users')->where('company_id', $family->company_id)->where('id', $family->responsible_owner_id)->first(['id', 'name', 'status']);
            $unit = $family->access_unit_id === null ? null : DB::table('organisation_units')->where('company_id', $family->company_id)->where('id', $family->access_unit_id)->first(['id', 'name', 'status']);
            $result = [
                'family_id' => $family->id, 'record_scope' => $path, 'created_by' => $family->created_by,
                'created_by_name' => DB::table('users')->where('company_id', $family->company_id)->where('id', $family->created_by)->value('name'),
                'responsible_owner' => $owner === null ? null : ['id' => (int) $owner->id, 'name' => $owner->name, 'status' => $owner->status],
                'access_unit' => $unit === null ? null : ['id' => (int) $unit->id, 'name' => $unit->name, 'status' => $unit->status],
                'baseline_state' => $family->access_baseline_state,
                'ownership_generation' => $family->ownership_generation, 'access_generation' => $family->access_generation,
                'allowed_actions' => $rights,
            ];
            if ($rights['assign']) {
                $rows = DB::table('quotation_ownership_events as event')
                    ->leftJoin('users as prior_owner', function ($join): void {
                        $join->on('prior_owner.company_id', '=', 'event.company_id')->on('prior_owner.id', '=', 'event.old_owner_id');
                    })
                    ->leftJoin('users as next_owner', function ($join): void {
                        $join->on('next_owner.company_id', '=', 'event.company_id')->on('next_owner.id', '=', 'event.new_owner_id');
                    })
                    ->leftJoin('users as actor', function ($join): void {
                        $join->on('actor.company_id', '=', 'event.company_id')->on('actor.id', '=', 'event.actor_id');
                    })
                    ->leftJoin('organisation_units as prior_unit', function ($join): void {
                        $join->on('prior_unit.company_id', '=', 'event.company_id')->on('prior_unit.id', '=', 'event.old_unit_id');
                    })
                    ->leftJoin('organisation_units as next_unit', function ($join): void {
                        $join->on('next_unit.company_id', '=', 'event.company_id')->on('next_unit.id', '=', 'event.new_unit_id');
                    })
                    ->where('event.company_id', $family->company_id)->where('event.family_id', $family->id)->when($ownershipBefore !== null, fn ($query) => $query->where('event.generation', '<', $ownershipBefore))->orderByDesc('event.generation')->limit($pageSize + 1)
                    ->get(['event.id', 'event.generation', 'event.kind', 'event.old_owner_id', 'event.new_owner_id', 'event.old_unit_id', 'event.new_unit_id', 'event.actor_id', 'event.recorded_at', 'prior_owner.name as old_owner_name', 'next_owner.name as new_owner_name', 'actor.name as actor_name', 'prior_unit.name as old_unit_name', 'next_unit.name as new_unit_name']);
                $result['ownership_history'] = $rows->take($pageSize)->map(fn ($row): array => (array) $row)->all();
                $result['ownership_history_has_more'] = $rows->count() > $pageSize;
                $result['ownership_history_next_generation'] = $rows->count() > $pageSize ? (int) $rows[$pageSize - 1]->generation : null;
            }
            if ($rights['delegate_view'] || $rights['revoke']) {
                $rows = DB::table('quotation_access_grants as g')
                    ->join('users as issuer', 'issuer.id', '=', 'g.issuer_id')->join('users as recipient', 'recipient.id', '=', 'g.recipient_id')
                    ->join('quotation_access_events as event', function ($join): void {
                        $join->on('event.company_id', '=', 'g.company_id')->on('event.family_id', '=', 'g.family_id')->on('event.grant_id', '=', 'g.id');
                    })
                    ->join('company_access_generations as epochs', 'epochs.company_id', '=', 'g.company_id')
                    ->leftJoin('quotation_access_revocations as r', function ($join): void {
                        $join->on('r.company_id', '=', 'g.company_id')->on('r.grant_id', '=', 'g.id');
                    })
                    ->leftJoin('users as revoker', function ($join): void {
                        $join->on('revoker.company_id', '=', 'r.company_id')->on('revoker.id', '=', 'r.revoked_by');
                    })
                    ->where('g.company_id', $family->company_id)->where('g.family_id', $family->id)->when($sharesBefore !== null, fn ($query) => $query->where('event.generation', '<', $sharesBefore))->orderByDesc('event.generation')->limit($pageSize + 1)
                    ->select(['g.id', 'event.generation as granted_generation', 'g.recipient_id', 'recipient.name as recipient_name', 'g.issuer_id', 'issuer.name as issuer_name', 'revoker.name as revoked_by_name', 'g.level', 'g.starts_at', 'g.ends_at', 'g.recorded_at', 'r.revoked_by', 'r.revoked_at'])
                    ->selectRaw("CASE WHEN r.id IS NOT NULL THEN 'REVOKED'
                        WHEN issuer.status <> 'ACTIVE' OR recipient.status <> 'ACTIVE'
                         OR g.issuer_authorization_generation <> issuer.authorization_generation OR g.recipient_authorization_generation <> recipient.authorization_generation
                         OR g.organisation_generation <> epochs.organisation_generation OR g.capability_generation <> epochs.capability_generation
                         OR g.ownership_generation <> ? THEN 'INVALIDATED'
                        WHEN g.ends_at IS NOT NULL AND g.ends_at <= statement_timestamp() THEN 'EXPIRED'
                        WHEN g.starts_at > statement_timestamp() THEN 'SCHEDULED' ELSE 'ACTIVE' END AS status", [$family->ownership_generation])->get();
                $result['shares'] = $rows->take($pageSize)->map(fn ($row): array => (array) $row)->all();
                $result['shares_has_more'] = $rows->count() > $pageSize;
                $result['shares_next_generation'] = $rows->count() > $pageSize ? (int) $rows[$pageSize - 1]->granted_generation : null;
            }

            return $result;
        });
    }

    /** @param list<string> $duties */
    private function may(User $actor, QuotationFamily $family, array $duties, bool $allowDelegation = false): bool
    {
        try {
            return app(QuotationAccess::class)->queryFor($actor, $duties, $allowDelegation)->whereKey($family->id)->exists();
        } catch (AuthorizationException) {
            return false;
        }
    }
}
