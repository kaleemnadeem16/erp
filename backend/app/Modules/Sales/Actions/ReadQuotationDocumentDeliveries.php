<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Models\QuotationDocumentDelivery;
use App\Modules\Sales\Support\Documents\QuotationDocumentLocator;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\SalesAccess;
use Illuminate\Support\Facades\DB;

/** The manual delivery history of one issued quotation: who recorded what, when, with corrections shown as such. Needs `sales.delivery.view` and the right to see the quotation. */
final class ReadQuotationDocumentDeliveries
{
    public function __construct(private readonly QuotationDocumentLocator $locator) {}

    /** @return list<QuotationDocumentDelivery> */
    public function __invoke(User $actor, int $revisionId): array
    {
        return DB::transaction(function () use ($actor, $revisionId): array {
            SalesAccess::require($actor, PermissionVocabulary::DELIVERY_VIEW, 'see quotation deliveries');
            $this->locator->authorise($actor, $revisionId, 'see quotation deliveries');
            $this->locator->manifest($revisionId);

            return QuotationDocumentDelivery::query()->where('revision_id', $revisionId)->orderBy('id')->get()->all();
        });
    }
}
