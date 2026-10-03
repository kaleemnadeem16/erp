<?php

namespace Tests\Support;

use App\Models\User;
use App\Modules\Approvals\Support\ApprovalFactType;
use App\Modules\Approvals\Support\ApprovalRouting;
use App\Modules\Approvals\Support\ApprovalSubject;

/** An in-memory subject type for exercising the approval engine without any real module. */
final class FakeApprovalSubject implements ApprovalSubject
{
    /** @var array<int, array{amount: string, warehouse: string}> */
    public array $documents = [];

    public function code(): string
    {
        return 'TEST_DOCUMENT';
    }

    public function label(): string
    {
        return 'Test document';
    }

    public function approvePermission(): string
    {
        return 'test.document.approve';
    }

    public function facts(): array
    {
        return [
            'amount_base' => ['label' => 'Amount', 'type' => ApprovalFactType::Decimal],
            'warehouse' => ['label' => 'Warehouse', 'type' => ApprovalFactType::String],
        ];
    }

    public function canViewEvidence(User $user): bool
    {
        return $user->can('test.document.cost.view');
    }

    public function routing(int $subjectId): ApprovalRouting
    {
        $document = $this->documents[$subjectId];

        return new ApprovalRouting(
            ['warehouse' => $document['warehouse']],
            $document['amount'],
            ['original_amount' => $document['amount'], 'currency' => 'PKR', 'fx_rate' => '1.000000000', 'fx_rate_date' => '2026-09-30', 'fx_rate_source' => 'manual', 'base_amount' => $document['amount']],
            ['number' => "T-{$subjectId}"],
        );
    }
}
