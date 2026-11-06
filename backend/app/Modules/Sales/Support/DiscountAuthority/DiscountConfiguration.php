<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\DiscountAuthority;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Models\SalesDiscountAssignment;
use App\Modules\Sales\Models\SalesDiscountBand;
use App\Modules\Sales\Models\SalesDiscountPolicy;
use App\Modules\Sales\Support\Kernel\ActiveActor;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\Approval\QuotationContentFingerprint;
use App\Modules\Sales\Support\Quotation\QuotationAccess;
use App\Support\CompanyContext;
use App\Support\CurrentCompany;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** @internal Read under the common fence. Missing/ambiguous assignments grant no routine authority. */
final class DiscountConfiguration
{
    public function mode(): string
    {
        return (string) (DB::table('sales_discount_mode_events')->where('company_id', app(CompanyContext::class)->id())->orderByDesc('id')->value('mode') ?? 'OFF');
    }

    public function today(): string
    {
        return now(app(CurrentCompany::class)->timezone())->format('Y-m-d');
    }

    public function policy(QuotationRevision $revision): SalesDiscountPolicy
    {
        $date = $this->today();
        $kind = QuotationContentFingerprint::concessions($revision);
        $unit = $revision->family->access_unit_id;
        $items = $revision->lines()->whereNotNull('item_id')->pluck('item_id')->all();
        $policies = SalesDiscountPolicy::query()->where('effective_from', '<=', $date)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('sales_discount_policy_activations as a')->whereColumn('a.policy_id', 'sales_discount_policy_versions.id')->whereColumn('a.company_id', 'sales_discount_policy_versions.company_id'))
            ->get()->groupBy('code')->map(fn ($versions) => $versions->sortByDesc('version_no')->first())
            ->filter(fn (SalesDiscountPolicy $p): bool => ($p->effective_to === null || $p->effective_to > $date)
                && ($p->customer_id === null || $p->customer_id === $revision->recipient_party_id)
                && ($p->unit_id === null || $p->unit_id === $unit) && ($p->contains_item_id === null || in_array($p->contains_item_id, $items, true))
                && ($p->operation === 'ANY' || str_contains($kind, $p->operation)));
        $policies = $policies->sortByDesc('priority')->values();
        if ($policies->isEmpty()) {
            throw AuthorityRefusal::make('AUTHORITY_POLICY_MISSING', 'No effective discount authority policy applies. Ask an administrator to configure one.');
        }
        if ($policies->count() > 1 && $policies[0]->priority === $policies[1]->priority) {
            throw AuthorityRefusal::make('AUTHORITY_POLICY_AMBIGUOUS', 'Equally ranked discount authority policies apply. Ask an administrator to resolve the conflict.');
        }
        if ($policies[0]->base_currency_id !== app(CurrentCompany::class)->baseCurrencyId()) {
            throw AuthorityRefusal::make('AUTHORITY_BASE_CURRENCY_CHANGED', 'The authority policy was written for another base currency. Publish a reviewed policy before continuing.');
        }

        return $policies[0];
    }

    public function assignment(User $person, QuotationRevision $revision, SalesDiscountPolicy $policy, string $purpose): ?SalesDiscountAssignment
    {
        $person = ActiveActor::current($person);
        if ($person === null || $person->must_change_password) {
            return null;
        }
        if ($purpose === 'ROUTINE') {
            try {
                app(QuotationAccess::class)->requireRevision($person, $revision->id, [PermissionVocabulary::QUOTATION_VIEW, PermissionVocabulary::QUOTATION_DISCOUNT]);
            } catch (AuthorizationException) {
                return null;
            } catch (DomainInvariantException $refusal) {
                if ($refusal->reasonCode === 'QUOTATION_NOT_FOUND') {
                    return null;
                }
                throw $refusal;
            }
        }
        $date = $this->today();
        $unit = $revision->family->access_unit_id;
        $bandIds = SalesDiscountBand::query()->where('policy_id', $policy->id)->pluck('id');
        $assignments = SalesDiscountAssignment::query()->where('user_id', $person->id)->whereIn('band_id', $bandIds)->whereIn('purpose', [$purpose, 'BOTH'])
            ->where('effective_from', '<=', $date)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $date))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('sales_discount_revocations as r')->whereColumn('r.assignment_id', 'sales_discount_assignments.id')->whereColumn('r.company_id', 'sales_discount_assignments.company_id'))
            ->get()->filter(function (SalesDiscountAssignment $a) use ($person, $unit): bool {
                if ($a->role_id !== null && (! DB::table('roles')->where('id', $a->role_id)->where('team_id', $a->company_id)->exists()
                    || ! DB::table('model_has_roles')->where('team_id', $a->company_id)->where('model_type', $person->getMorphClass())->where('model_id', $person->id)->where('role_id', $a->role_id)->exists())) {
                    return false;
                }
                if ($a->unit_id !== null && ($a->unit_id !== $unit || ! DB::table('organisation_unit_members as m')->join('organisation_units as u', 'u.id', '=', 'm.unit_id')
                    ->where('m.company_id', $a->company_id)->where('u.company_id', $a->company_id)->where('m.unit_id', $unit)->where('m.user_id', $person->id)->where('u.status', 'ACTIVE')->exists())) {
                    return false;
                }

                return true;
            });
        if ($assignments->count() > 1) {
            throw AuthorityRefusal::make('AUTHORITY_ASSIGNMENT_AMBIGUOUS', 'Conflicting employee authority assignments apply. Ask an administrator to resolve them.');
        }

        return $assignments->first();
    }
}
