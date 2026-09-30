<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Support;

/**
 * What a consuming module registers so the engine can route and verify its documents (ADR 0070 §1). The module
 * keeps its own document, permissions and state transitions; this only tells the engine the facts.
 */
interface ApprovalSubject
{
    /** Stable code stored on policies and requests, e.g. PURCHASE_ORDER. */
    public function code(): string;

    public function label(): string;

    /** The stable capability that makes a person eligible to approve in principle. */
    public function approvePermission(): string;

    /**
     * The facts a policy condition may use, with their kinds.
     *
     * @return array<string, array{label: string, type: ApprovalFactType}>
     */
    public function facts(): array;

    /** The routing of one document as it is right now. Throws if it does not exist. */
    public function routing(int $subjectId): ApprovalRouting;
}
