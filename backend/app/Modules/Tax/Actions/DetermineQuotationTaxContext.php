<?php

declare(strict_types=1);
namespace App\Modules\Tax\Actions;
use App\Models\User;
use App\Modules\Tax\Support\TaxAccess;
use App\Modules\Tax\Support\Quotation\QuotationTaxContext;
use App\Modules\Tax\Support\Quotation\QuotationTaxContextAuthorizer;
use App\Modules\Tax\Support\Quotation\QuotationTaxDeclarationResolver;
use App\Modules\Tax\Support\Quotation\QuotationTaxDetermination;
use App\Support\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;

final class DetermineQuotationTaxContext
{
    public function __construct(private readonly QuotationTaxContextAuthorizer $authorizer,private readonly QuotationTaxDeclarationResolver $resolver) {}
    public function __invoke(User $actor,QuotationTaxContext $context): QuotationTaxDetermination
    {
        $current=TaxAccess::currentActor($actor);
        if ($context->companyId!==app(CompanyContext::class)->id()) throw new AuthorizationException('Quotation tax context is not available to this actor.');
        $this->authorizer->authorise($current,$context);
        return $this->resolver->resolve($context);
    }
}
