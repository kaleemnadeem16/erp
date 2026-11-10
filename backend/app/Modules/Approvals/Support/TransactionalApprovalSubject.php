<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Support;

use App\Models\User;
use App\Modules\Approvals\Models\ApprovalRequest;
use Closure;

/** Opt in to owning-module fences, frozen evidence checks and fresh submission after stale/expired pending evidence. */
interface TransactionalApprovalSubject extends ApprovalSubject
{
    /** @param Closure(User): (ApprovalRequest|null) $work */
    public function approvalTransaction(int $subjectId, User $actor, Closure $work, string $operation): ?ApprovalRequest;

    /** @return array<string, mixed> */
    public function submissionSnapshot(int $subjectId, ApprovalRouting $routing): array;

    /** @return array<string, mixed> */
    public function decisionEvidence(User $actor, ApprovalRequest $request): array;

    public function requestHasExpired(ApprovalRequest $request): bool;

    /** Live evidence and request-wide expiry, including after the final approval. */
    public function assertRequestCurrent(ApprovalRequest $request): void;
}
