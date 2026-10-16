<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial\Controls;

/**
 * One control's verdict with its protected working. `reason` and `operands` are server-side evidence (the exact fractions compared); the reader-safe
 * projection drops both. A control that cannot be evaluated says why in `reason` and carries no operands it could not honestly compute.
 */
final readonly class ControlResult
{
    /** @param  array<string, array{numerator: string, denominator: string}>  $operands */
    public function __construct(public NetControl $control, public ControlOutcome $outcome, public string $reason, public array $operands = []) {}

    /** A mandatory violation or a mandatory control that could not be evaluated prevents issue; an advisory one never does. */
    public function blocksIssue(): bool
    {
        return $this->control->mode === ControlMode::Mandatory && ($this->outcome === ControlOutcome::Fail || $this->outcome === ControlOutcome::Unevaluable);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['control_id' => $this->control->id, 'control_version' => $this->control->version, 'kind' => $this->control->kind->value, 'mode' => $this->control->mode->value,
            'limit' => (string) $this->control->value, 'limit_currency_id' => $this->control->currencyId, 'basis' => $this->control->basis->value, 'exceptionable' => $this->control->exceptionable,
            'outcome' => $this->outcome->value, 'reason' => $this->reason, 'blocks_issue' => $this->blocksIssue(), 'operands' => $this->operands];
    }
}
