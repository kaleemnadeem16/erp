<?php

declare(strict_types=1);
namespace App\Modules\Tax\Support\Quotation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
final class UnavailableQuotationTaxConsumer implements QuotationTaxContextAuthorizer
{
    public function authorise(User $actor, QuotationTaxContext $context): void
    {
        throw new AuthorizationException('Quotation tax context is not available to this actor.');
    }
}
