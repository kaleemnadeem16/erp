<?php

declare(strict_types=1);

namespace App\Modules\Tax\Actions;

use App\Models\User;
use App\Modules\Tax\Support\Quotation\QuotationTaxContext;
use App\Modules\Tax\Support\Quotation\QuotationTaxDetermination;
use App\Modules\Tax\Support\TaxRefusal;

/** Read-only recheck, not a lock or issue transaction. Future issue must hold the agreed source/Tax locks itself. */
final class RequireCurrentQuotationTaxEvidence
{
    public function __construct(private readonly DetermineQuotationTaxContext $determine) {}

    public function __invoke(User $actor, QuotationTaxContext $context, QuotationTaxDetermination $expected): void
    {
        $current = ($this->determine)($actor, $context);
        if (! hash_equals($expected->fingerprint(), $current->fingerprint())) {
            throw TaxRefusal::because('TAX_CONTEXT_STALE', 'Tax evidence changed; determine it again.');
        }
    }
}
