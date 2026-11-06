<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Models\SalesPriceReference;
use App\Modules\Sales\Support\DiscountAuthority\AuthorityOperation;
use App\Modules\Sales\Support\DiscountAuthority\ReferenceInput;
use App\Modules\Sales\Support\Kernel\OperationIntent;
use App\Modules\Sales\Support\PermissionVocabulary;

final class ProposeSalesPriceReference
{
    /** @param array<string, mixed> $input */
    public function __invoke(User $actor, string $key, int $expectedGeneration, array $input): SalesPriceReference
    {
        $result = app(AuthorityOperation::class)->run($actor, PermissionVocabulary::FINANCIAL_CONFIGURE, $key, $expectedGeneration, ['operation' => 'reference.propose', 'input' => $input], function () use ($actor, $input): array {
            $data = ReferenceInput::parse($input);
            $data['version_no'] = (int) SalesPriceReference::query()->where('code', $data['code'])->max('version_no') + 1;
            $data['proposed_by'] = $actor->id;
            $data['fingerprint'] = OperationIntent::hash('sales.governed_reference.v1', $data);

            return ['id' => SalesPriceReference::create($data)->id];
        });

        return SalesPriceReference::query()->findOrFail($result['id']);
    }
}
