<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Authority;

use App\Support\PermissionRegistry;
use InvalidArgumentException;

/**
 * The Sales duty manifest (R9 AU0/AU1): which duties exist, which are live, and how the live ones are registered. It is the single place the Sales
 * vocabulary comes from, so a duty that is not ACTIVE here is not registered, has no grantable permission row, and is refused by `SalesAccess` even if
 * someone created the row by hand.
 *
 * Registration is declarative and idempotent (it replaces the module's vocabulary and exclusions each time) and grants nothing: it adds names to the
 * registry, and every name that is not an existing, non-sensitive CRM duty is excluded from automatic "every registered capability" grants. A duty
 * becomes ACTIVE only in the change that delivers its Action, guard and projection, and that change is also where its holder recipe is reviewed:
 * once active, a qualifying full-access mandate receives it on the next sync, which is the only automatic path left and is the reason the status
 * flip is a deliberate, reviewed act. The mandate mechanism itself (`FullAccessMandates`) is unchanged.
 */
final class SalesDutyManifest
{
    /** @var array<string, SalesDuty> */
    private array $duties = [];

    /** @param  list<SalesDuty>  $duties */
    public function __construct(array $duties)
    {
        foreach ($duties as $duty) {
            if (isset($this->duties[$duty->name])) {
                throw new InvalidArgumentException("Sales duty [{$duty->name}] is listed twice.");
            }
            $this->duties[$duty->name] = $duty;
        }
    }

    public static function shipped(): self
    {
        return new self(SalesDutyCatalogue::all());
    }

    /**
     * A copy of this manifest with the named STAGED duties switched to ACTIVE, for the local development preview only (`SalesDevPreview`). Only a staged duty may be
     * activated this way: an unknown, specified, dormant or already active name is refused rather than silently ignored.
     *
     * @param  list<string>  $names
     */
    public function withActivated(array $names): self
    {
        foreach ($names as $name) {
            if (($this->duties[$name] ?? null)?->status !== DutyStatus::Staged) {
                throw new InvalidArgumentException("Sales duty [{$name}] is not a staged duty and cannot be activated.");
            }
        }

        return new self(array_map(fn (SalesDuty $duty): SalesDuty => in_array($duty->name, $names, true)
            ? new SalesDuty($duty->name, $duty->class, DutyStatus::Active, $duty->operation, $duty->projection, $duty->deliveryGate, $duty->scope) : $duty, array_values($this->duties)));
    }

    /** @return list<SalesDuty> */
    public function all(): array
    {
        return array_values($this->duties);
    }

    public function find(string $name): ?SalesDuty
    {
        return $this->duties[$name] ?? null;
    }

    /** True only for a duty this manifest lists as ACTIVE; an unknown, specified or dormant name is not a Sales capability yet. */
    public function isActive(string $name): bool
    {
        return ($this->duties[$name] ?? null)?->isActive() === true;
    }

    /** @return list<string> */
    public function activeNames(): array
    {
        return array_values(array_map(fn (SalesDuty $duty): string => $duty->name, array_filter($this->duties, fn (SalesDuty $duty): bool => $duty->isActive())));
    }

    /** @return list<SalesDuty> */
    public function withStatus(DutyStatus $status): array
    {
        return array_values(array_filter($this->duties, fn (SalesDuty $duty): bool => $duty->status === $status));
    }

    /**
     * Active duties no automatic grant may include, grouped by the reason recorded with the exclusion.
     *
     * @return array<string, list<string>>
     */
    public function automaticGrantExclusions(): array
    {
        $groups = [];
        foreach ($this->duties as $duty) {
            if (! $duty->isActive() || $duty->automaticallyGrantable()) {
                continue;
            }
            $groups[$this->exclusionReason($duty)][] = $duty->name;
        }

        return $groups;
    }

    /** Register the live vocabulary and its exclusions. Safe to call repeatedly; grants nothing. */
    public function registerWith(PermissionRegistry $registry): void
    {
        $registry->registerVocabulary('sales', $this->activeNames());
        foreach ($this->automaticGrantExclusions() as $reason => $names) {
            $registry->excludeFromAutomaticGrants($names, $reason);
        }
    }

    private function exclusionReason(SalesDuty $duty): string
    {
        return match ($duty->class) {
            DutyClass::ExistingSensitive => 'Retrospective attribution is a sensitive duty: granted only explicitly or under a verified full-access mandate (ADR 0114).',
            DutyClass::NewOrdinary => 'New Phase 4 duty: no holder recipe is approved yet, so it is granted only explicitly or under a verified full-access mandate (ADR 0138, R9 rollout).',
            default => 'Sensitive Phase 4 duty: granted only to an explicit authorised holder or under a verified full-access mandate with scope proof (ADR 0138, R9 rollout).',
        };
    }
}
