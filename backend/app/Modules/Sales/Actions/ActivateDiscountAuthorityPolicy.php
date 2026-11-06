<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Models\SalesDiscountPolicy;
use App\Modules\Sales\Support\DiscountAuthority\AuthorityOperation;
use App\Modules\Sales\Support\DiscountAuthority\AuthorityRefusal;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;

final class ActivateDiscountAuthorityPolicy
{
    public function __invoke(User $actor, int $policyId, string $key, int $expectedGeneration, string $reason): SalesDiscountPolicy
    {
        $result = app(AuthorityOperation::class)->run($actor, PermissionVocabulary::FINANCIAL_CONFIGURE, $key, $expectedGeneration, ['operation' => 'policy.activate', 'id' => $policyId, 'reason' => $reason], function () use ($actor, $policyId, $reason): array {
            $policy = SalesDiscountPolicy::query()->findOrFail($policyId);
            $today = now(app(CurrentCompany::class)->timezone())->format('Y-m-d');
            $previous = SalesDiscountPolicy::query()->where('code', $policy->code)->whereExists(fn ($q) => $q->selectRaw('1')->from('sales_discount_policy_activations as a')->whereColumn('a.policy_id', 'sales_discount_policy_versions.id')->whereColumn('a.company_id', 'sales_discount_policy_versions.company_id'))->get();
            if (trim($reason) === '' || mb_strlen($reason) > 1000 || $policy->effective_from < $today) {
                throw AuthorityRefusal::make('AUTHORITY_INPUT_INVALID', 'Activation needs a reason and cannot be backdated.');
            }
            foreach ($previous as $old) {
                if ($old->version_no >= $policy->version_no || $old->effective_from >= $policy->effective_from) {
                    throw AuthorityRefusal::make('AUTHORITY_POLICY_OVERLAP', 'A successor policy must start after the earlier activated version.');
                }
            }
            DB::table('sales_discount_policy_activations')->insert(['company_id' => $policy->company_id, 'policy_id' => $policyId, 'actor_id' => $actor->id, 'reason' => trim($reason)]);

            return ['id' => $policyId];
        });

        return SalesDiscountPolicy::findOrFail($result['id']);
    }
}
