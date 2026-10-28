<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Support\Authority;

use InvalidArgumentException;

/**
 * The Jobs duty manifest: which duties exist and which are live. It is the one place `JobAccess` asks, so a name that is not ACTIVE here — unknown, specified or
 * staged — is refused whatever permission rows, scope grants or mandates exist. J-AU0 ships it with no ACTIVE duty and no registration: there is deliberately no
 * `registerWith`, because activating Jobs needs a shared, strict no-automatic-growth contract that does not exist yet (Task F plan, "Holder recipe and strict
 * automatic-growth gate").
 */
final class JobDutyManifest
{
    /** @var array<string, JobDuty> */
    private array $duties = [];

    /** @param  list<JobDuty>  $duties */
    public function __construct(array $duties)
    {
        foreach ($duties as $duty) {
            if (isset($this->duties[$duty->name])) {
                throw new InvalidArgumentException("Jobs duty [{$duty->name}] is listed twice.");
            }
            $this->duties[$duty->name] = $duty;
        }
    }

    public static function shipped(): self
    {
        return new self(JobDutyCatalogue::all());
    }

    /** @return list<JobDuty> */
    public function all(): array
    {
        return array_values($this->duties);
    }

    public function find(string $name): ?JobDuty
    {
        return $this->duties[$name] ?? null;
    }

    /** True only for a duty this manifest lists as ACTIVE; an unknown, specified or staged name is not a Jobs capability yet. */
    public function isActive(string $name): bool
    {
        return ($this->duties[$name] ?? null)?->isActive() === true;
    }

    /** @return list<string> */
    public function activeNames(): array
    {
        return array_values(array_map(fn (JobDuty $duty): string => $duty->name, array_filter($this->duties, fn (JobDuty $duty): bool => $duty->isActive())));
    }

    /** @return list<JobDuty> */
    public function withStatus(JobDutyStatus $status): array
    {
        return array_values(array_filter($this->duties, fn (JobDuty $duty): bool => $duty->status === $status));
    }
}
