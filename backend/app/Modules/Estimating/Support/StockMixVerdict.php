<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

use Brick\Math\BigDecimal;

/**
 * What the company's control says about one hand-built mix (ADR 0076). `observedPercent` is how far the mix's average
 * unit cost is above the proposal's; it is null when it could not be judged or was not asked for.
 */
final class StockMixVerdict
{
    public const NOT_CONTROLLED = 'NOT_CONTROLLED';

    public const WITHIN_LIMIT = 'WITHIN_LIMIT';

    public const NOT_JUDGED = 'NOT_JUDGED';

    public const REASON_MISSING = 'REASON_MISSING';

    public const REASON_PROVIDED = 'REASON_PROVIDED';

    public const APPROVAL_NEEDED = 'APPROVAL_NEEDED';

    public const BLOCKED = 'BLOCKED';

    /**
     * @param  array{policy: array{id: int, code: string, version_no: int, name: string}|null, steps: list<array{name: string, stage: int, required_approvals: int, uses_authority_limit: bool}>, note: string|null}|null  $approval
     * @param  list<string>  $limitations
     */
    public function __construct(
        public readonly string $status,
        public readonly ?BigDecimal $observedPercent,
        public readonly ?BigDecimal $extraCost,
        public readonly ?array $approval,
        public readonly array $limitations,
    ) {}

    /** Whether the mix could be adopted as it stands: nothing is outstanding and nothing could not be judged. */
    public function canAdopt(): bool
    {
        return in_array($this->status, [self::NOT_CONTROLLED, self::WITHIN_LIMIT, self::REASON_PROVIDED], true);
    }
}
