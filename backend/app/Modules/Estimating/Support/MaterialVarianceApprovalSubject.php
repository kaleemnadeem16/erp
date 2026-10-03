<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Approvals\Support\ApprovalFactType;
use App\Modules\Approvals\Support\ApprovalRouting;
use App\Modules\Approvals\Support\ApprovalSubject;

/**
 * How a material plan's variance tells the approval engine what it is (ADR 0070 §11, ADR 0054). The `APPROVAL_REQUIRED`
 * effect routes on these facts. A plan is only hypothetical until a quotation revision adopts it (Phase 4), so there is
 * no stored plan to submit yet: the preview builds the routing itself to say which approval a plan *would* need, and
 * `routing()` refuses until adoption exists rather than inventing a document.
 */
final class MaterialVarianceApprovalSubject implements ApprovalSubject
{
    public const CODE = 'MATERIAL_VARIANCE';

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return 'Material plan variance';
    }

    public function approvePermission(): string
    {
        return 'estimating.material_variance.approve';
    }

    public function facts(): array
    {
        return [
            'amount_base' => ['label' => 'Cost impact of the difference (base currency)', 'type' => ApprovalFactType::Decimal],
            'item_id' => ['label' => 'Material', 'type' => ApprovalFactType::Id],
            'category_id' => ['label' => 'Material category', 'type' => ApprovalFactType::Id],
        ];
    }

    /** The cost impact is internal cost: whoever may see internal cost may see it on the approval. */
    public function canViewEvidence(User $user): bool
    {
        return $user->can('estimating.internal_cost.view');
    }

    public function routing(int $subjectId): ApprovalRouting
    {
        throw new DomainInvariantException('A material plan can only be submitted for approval once it is adopted on a quotation revision; that arrives with Phase 4.', 'subject_id');
    }
}
