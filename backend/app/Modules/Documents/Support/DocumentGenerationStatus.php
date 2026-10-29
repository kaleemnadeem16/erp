<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

/**
 * The safe, stable status of a document generation: no payload, key, path or owner identity. `missing` means no intent exists under that identity.
 */
final readonly class DocumentGenerationStatus
{
    public const PENDING = 'pending';

    public const RUNNING = 'running';

    public const FAILED = 'failed';

    public const VERIFIED = 'verified';

    public const MISSING = 'missing';

    public function __construct(public int $manifestId, public string $purpose, public string $operationIdentity, public string $status, public ?string $errorCode) {}

    public static function fromState(string $state): string
    {
        return match ($state) {
            'PENDING' => self::PENDING,
            'RUNNING' => self::RUNNING,
            'FAILED' => self::FAILED,
            'VERIFIED' => self::VERIFIED,
            default => throw new DocumentException("Unknown generation state '{$state}'.", null, 'state_unknown'),
        };
    }
}
