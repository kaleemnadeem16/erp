<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Authority;

use InvalidArgumentException;

/** One Sales duty as the accepted matrix defines it: exact name, how it may be held, whether it is live, and what must exist before it is. */
final readonly class SalesDuty
{
    public DutyScope $scope;

    public function __construct(
        public string $name,
        public DutyClass $class,
        public DutyStatus $status,
        public string $operation,
        public string $projection,
        public string $deliveryGate,
    ) {
        if (! preg_match('/^sales(?:\.[a-z][a-z0-9-]*)+$/', $name)) {
            throw new InvalidArgumentException("[{$name}] is not an exact Sales duty name (no wildcards, one owning module).");
        }
        if ($class->isExisting() && $status !== DutyStatus::Active) {
            throw new InvalidArgumentException("Existing CRM duty [{$name}] cannot be anything but active.");
        }
        if ($class === DutyClass::DormantSensitive && $status === DutyStatus::Active) {
            throw new InvalidArgumentException("Dormant duty [{$name}] cannot be active.");
        }
        $this->scope = DutyScope::CompanyWide;
    }

    public function isActive(): bool
    {
        return $this->status === DutyStatus::Active;
    }

    /** May an automatic "every registered capability" grant include it? Only an existing, non-sensitive CRM duty keeps that behaviour; nothing new does. */
    public function automaticallyGrantable(): bool
    {
        return $this->class === DutyClass::Existing;
    }
}
