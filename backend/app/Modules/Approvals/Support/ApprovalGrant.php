<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Support;

use App\Models\User;
use App\Modules\Approvals\Models\ApprovalDelegation;

/**
 * The authority a person may use to decide one step: their own, or a delegator's (ADR 0070 §6). `principal` is
 * whose authority it is; `snapshot` is stored on the decision so it stays explainable when authority later changes.
 */
final class ApprovalGrant
{
    /** @param  array<string, mixed>  $snapshot */
    public function __construct(
        public readonly User $principal,
        public readonly ?ApprovalDelegation $delegation,
        public readonly array $snapshot,
    ) {}
}
