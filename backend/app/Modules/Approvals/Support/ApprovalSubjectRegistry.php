<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Support;

use App\Exceptions\DomainInvariantException;

/** The subject types modules have registered. Registration is code, so an unknown type is a programming or data error. */
final class ApprovalSubjectRegistry
{
    /** @var array<string, ApprovalSubject> */
    private array $subjects = [];

    public function register(ApprovalSubject $subject): void
    {
        $this->subjects[$subject->code()] = $subject;
    }

    public function has(string $code): bool
    {
        return isset($this->subjects[$code]);
    }

    public function get(string $code): ApprovalSubject
    {
        return $this->subjects[$code] ?? throw new DomainInvariantException("Unknown approval subject type {$code}.", 'subject_type');
    }

    /** @return array<string, ApprovalSubject> */
    public function all(): array
    {
        return $this->subjects;
    }
}
