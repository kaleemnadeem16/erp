<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation\Issue;

use InvalidArgumentException;

/**
 * The tax of ONE line as the Tax module determined and calculated it: the disposition, the settled tax and gross, and an opaque evidence reference. Nothing else — no rate,
 * profile fact or ground — crosses into Sales. Amounts are exact storage decimals; a missing amount is not a line tax at all (the outcome would be unknown).
 */
final readonly class QuotationLineTax
{
    public const DISPOSITIONS = ['TAXABLE', 'ZERO_RATED', 'EXEMPT', 'NOT_APPLICABLE'];

    public function __construct(public int $lineId, public string $disposition, public string $tax, public string $gross, public string $evidenceReference)
    {
        if (! in_array($disposition, self::DISPOSITIONS, true) || preg_match('/^\d+(\.\d{1,4})?$/', $tax) !== 1 || preg_match('/^\d+(\.\d{1,4})?$/', $gross) !== 1
            || preg_match('/^[0-9a-f]{64}$/', $evidenceReference) !== 1) {
            throw new InvalidArgumentException('A line tax is a known disposition, exact tax and gross amounts and a 64-character evidence reference.');
        }
    }
}
