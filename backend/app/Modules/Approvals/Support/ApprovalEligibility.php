<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Support;

use App\Models\User;
use App\Modules\Administration\Support\MemberStatus;
use App\Modules\Approvals\Models\ApprovalAuthority;
use App\Modules\Approvals\Models\ApprovalDelegation;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Models\ApprovalRequestStep;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;

/**
 * Whether someone may decide a step — a stable capability plus, when the step uses limits, an amount ceiling held
 * as data — directly or by acting under a delegation. A delegate never brings authority of their own: the
 * delegation is valid only while the *delegator* would be eligible, and only their permission and ceiling count.
 */
final class ApprovalEligibility
{
    public function __construct(private readonly ApprovalSubjectRegistry $subjects) {}

    public function grantFor(User $actor, ApprovalRequest $request, ApprovalRequestStep $step): ?ApprovalGrant
    {
        if (! $this->canAct($actor)) {
            return null;
        }
        $own = $this->directGrant($actor, $request, $step);
        if ($own !== null) {
            return $own;
        }

        $delegations = ApprovalDelegation::query()
            ->inForce()
            ->where('delegate_id', $actor->id)
            ->where(fn ($q) => $q->whereNull('subject_type')->orWhere('subject_type', $request->subject_type))
            ->with('delegator')
            ->orderBy('id')
            ->get();
        foreach ($delegations as $delegation) {
            $delegator = $delegation->delegator;
            $grant = $this->directGrant($delegator, $request, $step);
            if ($grant !== null) {
                return new ApprovalGrant($delegator, $delegation, $grant->snapshot + ['delegation_id' => $delegation->id, 'acting_for' => $delegator->id]);
            }
        }

        return null;
    }

    private function directGrant(User $person, ApprovalRequest $request, ApprovalRequestStep $step): ?ApprovalGrant
    {
        if (! $this->canAct($person)) {
            return null;
        }
        $policyStep = $step->policyStep;
        $permission = $policyStep->permission ?? $this->subjects->get($request->subject_type)->approvePermission();
        $viaEscalation = false;
        if (! $person->can($permission)) {
            // A step that has gone overdue may widen to people holding its escalation capability (ADR 0070 §9).
            if ($step->overdue_at !== null && $policyStep->escalation_permission !== null && $person->can($policyStep->escalation_permission)) {
                $viaEscalation = true;
                $permission = $policyStep->escalation_permission;
            } else {
                return null;
            }
        }

        $snapshot = ['permission' => $permission, 'escalation' => $viaEscalation, 'ceiling' => null, 'unlimited' => null];
        if ($policyStep->use_authority_limit) {
            $amount = $request->facts['amount_base'] ?? null;
            $authority = $amount === null ? null : $this->authorityCovering($person, $request->subject_type, BigDecimal::of((string) $amount));
            if ($authority === null) {
                return null;
            }
            $snapshot['unlimited'] = $authority->unlimited;
            $snapshot['ceiling'] = $authority->ceiling_base_amount;
            $snapshot['authority_id'] = $authority->id;
        }

        return new ApprovalGrant($person, null, $snapshot);
    }

    private function canAct(User $person): bool
    {
        return $person->status === MemberStatus::Active && ! $person->must_change_password;
    }

    private function authorityCovering(User $person, string $subjectType, BigDecimal $amount): ?ApprovalAuthority
    {
        return ApprovalAuthority::query()
            ->inForce(Carbon::today())
            ->where('user_id', $person->id)
            ->where('subject_type', $subjectType)
            ->orderByDesc('unlimited')
            ->orderByDesc('ceiling_base_amount')
            ->get()
            ->first(fn (ApprovalAuthority $a): bool => $a->unlimited || BigDecimal::of((string) $a->ceiling_base_amount)->isGreaterThanOrEqualTo($amount));
    }
}
