<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial\Controls;

use App\Modules\Sales\Support\Kernel\OperationIntent;

/**
 * The result of judging one settled line against a control set. Overall outcome follows one precedence — FAIL, then UNEVALUABLE, then WARN, then PASS —
 * and `blocksIssue()` is true when any mandatory control failed or could not be evaluated. An advisory violation is WARN and an advisory control that
 * cannot be evaluated is UNEVALUABLE; both ask for review and neither blocks. An empty set is a PASS that says it judged nothing (`evaluated` is 0).
 * No bypass or exception is modelled: whether a later, separately authorised exception may relax a named control is not decided here.
 *
 * `toInternalArray()` and `fingerprint()` carry protected operands and are for the server and its immutable evidence only; readers get `consumerSafe()`.
 */
final readonly class NetControlEvaluation
{
    public const CALCULATION_VERSION = 'FR4.3';

    /** @param  list<ControlResult>  $results */
    public function __construct(public NetControlSet $set, public array $results) {}

    public function outcome(): ControlOutcome
    {
        $worst = ControlOutcome::Pass;
        foreach ($this->results as $result) {
            if ($result->outcome->severity() > $worst->severity()) {
                $worst = $result->outcome;
            }
        }

        return $worst;
    }

    public function blocksIssue(): bool
    {
        foreach ($this->results as $result) {
            if ($result->blocksIssue()) {
                return true;
            }
        }

        return false;
    }

    /** Any warning or any control that could not be judged asks a person to look, even when nothing blocks. */
    public function requiresReview(): bool
    {
        foreach ($this->results as $result) {
            if ($result->outcome !== ControlOutcome::Pass) {
                return true;
            }
        }

        return false;
    }

    public function result(string $controlId): ?ControlResult
    {
        foreach ($this->results as $result) {
            if ($result->control->id === $controlId) {
                return $result;
            }
        }

        return null;
    }

    public function consumerSafe(): NetControlOutcomeView
    {
        return new NetControlOutcomeView($this->outcome()->value, $this->blocksIssue(), $this->requiresReview(),
            array_map(static fn (ControlResult $r): array => ['ref' => $r->control->id, 'outcome' => $r->outcome->value, 'blocks_issue' => $r->blocksIssue()], $this->results));
    }

    /** @return array<string, mixed> server-side only */
    public function toInternalArray(): array
    {
        return ['calculation_version' => self::CALCULATION_VERSION, 'company_id' => $this->set->companyId, 'control_set_version' => $this->set->version, 'control_set_fingerprint' => $this->set->fingerprint(),
            'evaluated' => count($this->results), 'outcome' => $this->outcome()->value, 'blocks_issue' => $this->blocksIssue(), 'requires_review' => $this->requiresReview(),
            'results' => array_map(static fn (ControlResult $r): array => $r->toArray(), $this->results)];
    }

    /** Identifies this exact evaluation including its protected operands: never expose it to a reader. */
    public function fingerprint(): string
    {
        return OperationIntent::hash('fr4.net_evaluation', $this->toInternalArray());
    }
}
