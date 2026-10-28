<?php

declare(strict_types=1);
namespace App\Modules\Tax\Support\Quotation;
use App\Models\User;

/** A future server-owned consumer must re-prove current document/Party/source entitlement and these exact facts.
 * Merely holding a Tax configuration duty or supplying a Party id is insufficient. No consumer is shipped here. */
interface QuotationTaxContextAuthorizer
{
    public function authorise(User $actor, QuotationTaxContext $context): void;
}
