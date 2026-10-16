<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial\Controls;

use App\Modules\Sales\Support\Financial\StorageAmount;
use Brick\Math\BigDecimal;

/**
 * The governed pre-discretionary price a reduction is measured against, for the whole line, in its own currency (ADR 0116). It is supplied by the pricing
 * that resolved it — changing a manual base cannot reset it — and is never inferred here. `label` names where it came from for the evidence.
 */
final readonly class GovernedReference
{
    public BigDecimal $amount;

    public function __construct(string|BigDecimal $amount, public int $currencyId, public string $label)
    {
        $this->amount = StorageAmount::of($amount, 'reference');
        if (trim($label) === '') {
            throw ControlRefusal::make(ControlRefusal::FACTS_INVALID, 'A governed reference must say what it is.', 'reference');
        }
    }
}
