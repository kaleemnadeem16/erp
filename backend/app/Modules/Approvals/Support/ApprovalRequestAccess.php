<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Support;

use App\Models\User;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Support\CompanyContext;

final class ApprovalRequestAccess
{
    public function __construct(private readonly ApprovalInbox $inbox) {}

    public function canView(User $user, ApprovalRequest $request): bool
    {
        if ($user->company_id !== $request->company_id || $request->company_id !== app(CompanyContext::class)->id()) {
            return false;
        }
        $request->loadMissing('steps.decisions');

        return $request->submitted_by === $user->id || $user->can('approvals.history.view')
            || $request->steps->flatMap->decisions->contains(fn ($decision): bool => in_array($user->id, [$decision->decided_by, $decision->principal_id], true))
            || ($request->isOpen() && $this->inbox->actionableStep($user, $request) !== null);
    }
}
