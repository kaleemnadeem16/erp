<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Support;

use App\Models\User;

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

    /**
     * May this person see the figures an approval carries about the document — the routed amount and its currency evidence —
     * *because of their own standing with the document's data*, not because they hold an approval role? The subject's module
     * owns its field policy, so it answers (a purchase order: whoever may see its commercial cost). Whoever is being asked to
     * decide, or has decided, also sees them for that one request (see ApprovalEvidenceAccess); everyone else gets the
     * approval's status and decisions without the money. A summary the subject returns must never carry a cost figure.
     */
    public function canViewEvidence(User $user): bool;
}
