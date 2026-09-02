<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Support;

enum DispositionOutcome: string
{
    case Pass = 'PASS';
    case AcceptWithConcession = 'ACCEPT_WITH_CONCESSION';
    case ReturnToSupplier = 'RETURN_TO_SUPPLIER';
    case Rework = 'REWORK';
    case Scrap = 'SCRAP';
    case PendingDecision = 'PENDING_DECISION';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Rework, self::PendingDecision => false,
            default => true,
        };
    }

    /**
     * Independent of isTerminal(): SCRAP/RETURN_TO_SUPPLIER are terminal (no further
     * disposition may be posted against them) but must never count as available stock.
     */
    public function releasesStockToAvailable(): bool
    {
        return match ($this) {
            self::Pass, self::AcceptWithConcession => true,
            default => false,
        };
    }

    /** @return list<string> */
    public static function terminalValues(): array
    {
        return array_values(array_map(
            fn (self $case) => $case->value,
            array_filter(self::cases(), fn (self $case) => $case->isTerminal()),
        ));
    }
}
