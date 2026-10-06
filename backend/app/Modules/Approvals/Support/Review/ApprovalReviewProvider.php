<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Support\Review;

interface ApprovalReviewProvider
{
    public function subjectType(): string;

    /** @return array<string, mixed> Explicit versioned whitelist, never a raw model. */
    public function capture(int $subjectId): array;

    public function fingerprint(int $subjectId): string;
}
