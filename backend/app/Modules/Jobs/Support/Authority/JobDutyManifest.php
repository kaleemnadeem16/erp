<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Support\Authority;

use App\Support\PermissionRegistry;
use InvalidArgumentException;

/**
 * The Jobs duty manifest: which duties exist and which are live. It is the one place `JobAccess` asks, so a name that is not ACTIVE here — unknown, specified or
 * staged — is refused whatever permission rows, scope grants or mandates exist. It ships with no ACTIVE duty, so `registerWith` registers nothing today. Once a duty
 * is ACTIVE, `registerWith` registers it AND strictly excludes it from every automatic grant, mandates included ({@see PermissionRegistry::excludeStrictly}): a Jobs
 * duty is only ever held by an explicit, audited assignment (Task F plan, "Holder recipe and strict automatic-growth gate").
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

    /** Register the live vocabulary and strictly exclude it from automatic growth. Safe to call repeatedly; grants nothing, and does nothing while no duty is ACTIVE. */
    public function registerWith(PermissionRegistry $registry): void
    {
        $names = $this->activeNames();
        if ($names === []) {
            return;
        }
        $registry->registerVocabulary('jobs', $names);
        $registry->excludeStrictly($names, 'Jobs duty: granted only by an explicit, audited assignment; never by an automatic grant, an administrator sync or a full-access mandate.');
    }

    /** @return list<JobDuty> */
    public function withStatus(JobDutyStatus $status): array
    {
        return array_values(array_filter($this->duties, fn (JobDuty $duty): bool => $duty->status === $status));
    }
}
