<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation\Issue;

/**
 * One named reason a draft cannot be issued yet. A code and where it applies — never an amount, a cost, a rate or any free text — so a readiness result can be shown
 * to a preparer and logged without carrying anything protected.
 */
final readonly class IssueBlocker
{
    public const HEADER = 'HEADER';

    public const LINE = 'LINE';

    public const TAX = 'TAX';

    public const CONTROLS = 'CONTROLS';

    public const AUTHORITY = 'AUTHORITY';

    public function __construct(public string $code, public string $scope, public ?int $lineId = null) {}

    /** @return array{code: string, scope: string, line_id: int|null} */
    public function toArray(): array
    {
        return ['code' => $this->code, 'scope' => $this->scope, 'line_id' => $this->lineId];
    }
}
