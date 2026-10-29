<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Models\QuotationDocumentDelivery;
use App\Modules\Sales\Support\Documents\QuotationDocumentLocator;
use App\Modules\Sales\Support\PermissionVocabulary;
use Illuminate\Auth\Access\AuthorizationException;

/** The manual delivery history of one issued quotation: who recorded what, when, with corrections shown as such. Needs `sales.delivery.view` and the right to see the quotation. */
final class ReadQuotationDocumentDeliveries
{
    public function __construct(private readonly QuotationDocumentLocator $locator) {}

    /** @return list<QuotationDocumentDelivery> */
    public function __invoke(User $actor, int $revisionId): array
    {
        if (! QuotationDocumentLocator::mayUse($actor, PermissionVocabulary::DELIVERY_VIEW)) {
            throw new AuthorizationException('You are not permitted to see quotation deliveries.');
        }
        $this->locator->manifest($revisionId);

        return QuotationDocumentDelivery::query()->where('revision_id', $revisionId)->orderBy('id')->get()->all();
    }
}
