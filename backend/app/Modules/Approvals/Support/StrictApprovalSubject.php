<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Support;

/** Opt-in policy selection. Other consumers retain ADR 0070's selection semantics. */
interface StrictApprovalSubject extends ApprovalSubject
{
    public function strictPolicySelection(): bool;

    public function policySelectionDate(): string;
}
