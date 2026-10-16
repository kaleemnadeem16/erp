<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial\Policy;

/**
 * What a draft remembers about the policy it was prepared under, so a later change can be noticed (ADR 0144 §4). It is evidence of the policy's rules by
 * fingerprint, with the version for display; it holds no price and rewrites nothing.
 */
final readonly class RoundingPolicyBinding
{
    public function __construct(public string $fingerprint, public ?int $versionNo, public string $asOfDate) {}

    public static function capture(RoundingPolicy $policy, string $asOfDate): self
    {
        return new self($policy->fingerprint(), $policy->versionNo, $asOfDate);
    }

    /** Is the policy now in force the one this was prepared under? Identical rules under a new version number are still current. */
    public function isCurrent(RoundingPolicy $now): bool
    {
        return hash_equals($this->fingerprint, $now->fingerprint());
    }

    /** @return array{fingerprint: string, version_no: int|null, as_of: string} */
    public function toArray(): array
    {
        return ['fingerprint' => $this->fingerprint, 'version_no' => $this->versionNo, 'as_of' => $this->asOfDate];
    }
}
