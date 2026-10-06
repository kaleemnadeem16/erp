<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Support\Review;

use App\Modules\Approvals\Models\ApprovalRequest;
use App\Support\CompanyContext;

final class ApprovalReviewRegistry
{
    /** @var array<string, ApprovalReviewProvider> */
    private array $providers = [];

    /** @var array<string, string|null> Explicit technical duty owned by each consuming subject. */
    private array $technicalPermissions = [];

    public function register(ApprovalReviewProvider $provider, ?string $technicalPermission = null): void
    {
        $this->providers[$provider->subjectType()] = $provider;
        $this->technicalPermissions[$provider->subjectType()] = $technicalPermission;
    }

    public function technicalPermission(string $subjectType): ?string
    {
        return $this->technicalPermissions[$subjectType] ?? null;
    }

    public function has(string $subjectType): bool
    {
        return isset($this->providers[$subjectType]);
    }

    /** @return array<string, mixed>|null */
    public function capture(string $subjectType, int $subjectId): ?array
    {
        return isset($this->providers[$subjectType]) ? $this->providers[$subjectType]->capture($subjectId) : null;
    }

    public function available(ApprovalRequest $request): bool
    {
        if (! $this->has($request->subject_type)) {
            return true;
        }
        $snapshot = $request->facts['document_review'] ?? null;

        return is_array($snapshot) && ($snapshot['schema_version'] ?? null) === 1
            && ($snapshot['company_id'] ?? null) === $request->company_id
            && $request->company_id === app(CompanyContext::class)->id()
            && ($snapshot['subject_id'] ?? null) === $request->subject_id
            && is_string($snapshot['fingerprint'] ?? null)
            && is_array($snapshot['operational'] ?? null) && $snapshot['operational'] !== []
            && is_array($snapshot['commercial'] ?? null) && $snapshot['commercial'] !== [];
    }

    public function unchanged(ApprovalRequest $request): bool
    {
        // No invented evidence for legacy completed approvals or subjects not yet adopting review.
        if (! $this->has($request->subject_type) || ! isset($request->facts['document_review'])) {
            return true;
        }

        return $this->available($request)
            && $this->providers[$request->subject_type]->fingerprint($request->subject_id) === $request->facts['document_review']['fingerprint'];
    }
}
