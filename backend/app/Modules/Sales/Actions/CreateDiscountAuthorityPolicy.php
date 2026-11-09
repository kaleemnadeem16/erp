<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Models\SalesDiscountBand;
use App\Modules\Sales\Models\SalesDiscountPolicy;
use App\Modules\Sales\Support\DiscountAuthority\AuthorityOperation;
use App\Modules\Sales\Support\DiscountAuthority\AuthorityRefusal;
use App\Modules\Sales\Support\DiscountAuthority\DiscountPolicyInput;
use App\Modules\Sales\Support\Kernel\ActiveActor;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\SalesAccess;
use App\Support\CurrentCompany;

final class CreateDiscountAuthorityPolicy
{
    /** @param array<string, mixed> $input */
    public function __invoke(User $actor, string $key, int $expectedGeneration, array $input): SalesDiscountPolicy
    {
        SalesAccess::require(ActiveActor::require($actor), PermissionVocabulary::FINANCIAL_CONFIGURE);
        $result = app(AuthorityOperation::class)->run($actor, PermissionVocabulary::FINANCIAL_CONFIGURE, $key, $expectedGeneration, ['operation' => 'policy.create', 'input' => $input], function () use ($actor, $input): array {
            $base = app(CurrentCompany::class)->baseCurrencyId()
                ?? throw AuthorityRefusal::make('AUTHORITY_BASE_CURRENCY_REQUIRED', 'Set the company base currency before configuring monetary authority.', 'base_currency_id');
            $data = DiscountPolicyInput::parse($input);
            $bands = $data['bands'];
            unset($data['bands']);
            $data['created_by'] = $actor->id;
            $data['base_currency_id'] = $base;
            $data['version_no'] = (int) SalesDiscountPolicy::query()->where('code', $data['code'])->max('version_no') + 1;
            $policy = SalesDiscountPolicy::create($data);
            foreach ($bands as $band) {
                SalesDiscountBand::create($band + ['policy_id' => $policy->id]);
            }

            return ['id' => $policy->id];
        });

        return SalesDiscountPolicy::query()->findOrFail($result['id']);
    }
}
