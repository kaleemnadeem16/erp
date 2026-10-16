<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial\Controls;

use App\Modules\Sales\Support\Kernel\OperationIntent;

/**
 * The versioned set of controls in force for one company. `roundingFingerprint`, when set, names the rounding policy these controls were written against:
 * a final net that was settled under a different one is not comparable and every control then reports UNEVALUABLE rather than a false PASS or FAIL.
 */
final readonly class NetControlSet
{
    /** @param  list<mixed>  $controls NetControl values; anything else is refused */
    public function __construct(public int $companyId, public int $version, public array $controls, public ?string $roundingFingerprint = null)
    {
        $ids = [];
        foreach ($controls as $control) {
            if (! $control instanceof NetControl || isset($ids[$control->id])) {
                throw ControlRefusal::make(ControlRefusal::CONTROL_INVALID, 'Controls must be NetControl values with distinct ids.', 'controls');
            }
            $ids[$control->id] = true;
        }
        if ($version < 1) {
            throw ControlRefusal::make(ControlRefusal::CONTROL_INVALID, 'A control set has a positive version.', 'version');
        }
    }

    /** What the set *is*, so evidence can show which rules judged a line. Server-side only: it covers the limits. */
    public function fingerprint(): string
    {
        $controls = array_map(static fn (NetControl $c): array => ['id' => $c->id, 'version' => $c->version, 'kind' => $c->kind->value, 'mode' => $c->mode->value, 'value' => (string) $c->value,
            'currency_id' => $c->currencyId, 'basis' => $c->basis->value], $this->controls);
        usort($controls, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

        return OperationIntent::hash('fr4.net_control_set', ['company_id' => $this->companyId, 'version' => $this->version, 'rounding' => $this->roundingFingerprint, 'controls' => $controls]);
    }
}
