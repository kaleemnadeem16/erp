<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

use App\Exceptions\DomainInvariantException;
use App\Modules\Approvals\Support\ApprovalFactType;
use App\Modules\Approvals\Support\ApprovalRouting;
use App\Modules\Approvals\Support\ApprovalSubject;

/**
 * How a hand-chosen stock mix tells the approval engine what it is (ADR 0076). Like a material plan's variance, a mix is
 * only hypothetical until a plan is adopted (Phase 4), so there is no stored mix to submit yet: the preview builds the
 * routing itself to say which approval a mix *would* need, and `routing()` refuses until adoption exists.
 */
final class StockMixApprovalSubject implements ApprovalSubject
{
    public const CODE = 'STOCK_MIX';

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return 'Hand-chosen stock';
    }

    public function approvePermission(): string
    {
        return 'estimating.stock_mix.approve';
    }

    public function facts(): array
    {
        return [
            'amount_base' => ['label' => 'Extra cost over the proposal (base currency)', 'type' => ApprovalFactType::Decimal],
            'cost_difference_percent' => ['label' => 'Cost above the proposal (%)', 'type' => ApprovalFactType::Decimal],
            'item_id' => ['label' => 'Material', 'type' => ApprovalFactType::Id],
            'category_id' => ['label' => 'Material category', 'type' => ApprovalFactType::Id],
        ];
    }

    public function routing(int $subjectId): ApprovalRouting
    {
        throw new DomainInvariantException('A hand-chosen stock mix can only be submitted for approval once its plan is adopted on a quotation revision; that arrives with Phase 4.', 'subject_id');
    }
}
