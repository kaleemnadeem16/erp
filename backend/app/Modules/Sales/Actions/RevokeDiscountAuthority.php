<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Models\SalesDiscountAssignment;
use App\Modules\Sales\Support\DiscountAuthority\AuthorityOperation;
use App\Modules\Sales\Support\DiscountAuthority\AuthorityRefusal;
use App\Modules\Sales\Support\PermissionVocabulary;
use Illuminate\Support\Facades\DB;

final class RevokeDiscountAuthority
{
    /** @return array<string, mixed> */
    public function __invoke(User $actor, int $assignmentId, string $key, int $expectedGeneration, string $reason): array
    {
        return app(AuthorityOperation::class)->run($actor, PermissionVocabulary::FINANCIAL_CONFIGURE, $key, $expectedGeneration, ['operation' => 'assignment.revoke', 'id' => $assignmentId, 'reason' => $reason], function () use ($actor, $assignmentId, $reason): array {
            $assignment = SalesDiscountAssignment::findOrFail($assignmentId);
            if (trim($reason) === '' || mb_strlen($reason) > 1000) {
                throw AuthorityRefusal::make('AUTHORITY_INPUT_INVALID', 'Give a reason of up to 1000 characters.', 'reason');
            }
            $id = DB::table('sales_discount_revocations')->insertGetId(['company_id' => $assignment->company_id, 'assignment_id' => $assignmentId, 'actor_id' => $actor->id, 'reason' => trim($reason)]);

            return ['id' => $id];
        });
    }
}
