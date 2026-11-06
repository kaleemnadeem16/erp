<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Models\SalesPriceReference;
use App\Modules\Sales\Support\DiscountAuthority\AuthorityOperation;
use App\Modules\Sales\Support\DiscountAuthority\AuthorityRefusal;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;

final class ApproveSalesPriceReference
{
    public function __invoke(User $actor, int $referenceId, string $key, int $expectedGeneration, string $reason): SalesPriceReference
    {
        $result = app(AuthorityOperation::class)->run($actor, PermissionVocabulary::PRICE_REFERENCE_APPROVE, $key, $expectedGeneration, ['operation' => 'reference.approve', 'reference_id' => $referenceId, 'reason' => $reason], function () use ($actor, $referenceId, $reason): array {
            $reference = SalesPriceReference::query()->findOrFail($referenceId);
            if ($reference->proposed_by === $actor->id) {
                throw AuthorityRefusal::make('REFERENCE_INDEPENDENT_APPROVAL_REQUIRED', 'Someone other than the reference proposer must approve this price.');
            }
            if (trim($reason) === '' || mb_strlen($reason) > 1000) {
                throw AuthorityRefusal::make('AUTHORITY_INPUT_INVALID', 'Give a reason of up to 1000 characters.', 'reason');
            }
            $zone = app(CurrentCompany::class)->timezone();
            if ($reference->effective_from < now($zone)->format('Y-m-d')) {
                throw AuthorityRefusal::make('REFERENCE_BACKDATE_REFUSED', 'A new reference activation cannot be backdated.', 'effective_from');
            }
            $active = DB::table('sales_price_reference_activations')->where('company_id', $reference->company_id)->where('reference_id', $reference->id)->exists();
            if ($active) {
                throw AuthorityRefusal::make('REFERENCE_ALREADY_APPROVED', 'This reference version has already been approved.');
            }
            DB::table('sales_price_reference_activations')->insert(['company_id' => $reference->company_id, 'reference_id' => $reference->id, 'approved_by' => $actor->id, 'reason' => trim($reason)]);

            return ['id' => $reference->id];
        });

        return SalesPriceReference::query()->findOrFail($result['id']);
    }
}
