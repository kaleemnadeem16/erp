<?php

declare(strict_types=1);

namespace App\Support\JobSource;

use InvalidArgumentException;

/** One family's answer: NOT_APPLICABLE (no supported validated Job binding exists), APPLICABLE (one does) or UNKNOWN (cannot be proved). Pinned by contract version. */
final readonly class FamilyAssessment
{
    public const NOT_APPLICABLE = 'NOT_APPLICABLE';

    public const APPLICABLE = 'APPLICABLE';

    public const UNKNOWN = 'UNKNOWN';

    /** @param  list<string>  $supportedBindings the explicitly supported, validated Job bindings of this family (empty while none is delivered) */
    public function __construct(public string $family, public string $status, public string $contractVersion, public array $supportedBindings = [], public ?string $reason = null)
    {
        if (! in_array($status, [self::NOT_APPLICABLE, self::APPLICABLE, self::UNKNOWN], true)) {
            throw new InvalidArgumentException("[{$status}] is not a source applicability status.");
        }
        if (trim($family) === '' || trim($contractVersion) === '') {
            throw new InvalidArgumentException('A family assessment needs a family and a contract version.');
        }
        if ($status === self::NOT_APPLICABLE && $supportedBindings !== []) {
            throw new InvalidArgumentException('A family with supported Job bindings cannot be NOT_APPLICABLE.');
        }
    }

    public static function notApplicable(string $family, string $contractVersion): self
    {
        return new self($family, self::NOT_APPLICABLE, $contractVersion);
    }

    public static function unknown(string $family, string $contractVersion, string $reason): self
    {
        return new self($family, self::UNKNOWN, $contractVersion, [], $reason);
    }

    /** @param  list<string>  $bindings */
    public static function applicable(string $family, string $contractVersion, array $bindings): self
    {
        return new self($family, self::APPLICABLE, $contractVersion, $bindings);
    }
}
