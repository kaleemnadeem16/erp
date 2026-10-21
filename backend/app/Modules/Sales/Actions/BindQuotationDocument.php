<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Documents\Actions\BindDocumentSubject;
use App\Modules\Documents\Support\DocumentProjection;
use App\Modules\Sales\Models\QuotationCustomerDocumentSnapshot;
use App\Modules\Sales\Models\QuotationDocumentBinding;
use App\Modules\Sales\Support\Documents\QuotationCustomerDocumentAdapter;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\SalesAccess;
use Illuminate\Support\Facades\DB;

/**
 * Binds a frozen quotation customer snapshot to a Documents identity inside Sales' own transaction: Documents validates the projection against Sales' allowlist
 * and records the identity idempotently, then Sales records its own row with the real foreign keys. A retry resolves the same binding and creates no second
 * archive identity. INTERNAL: no route or caller exists, and no snapshot writer exists yet (QL3/DF2). Guarded by `sales.document.generate`, a STAGED duty, so
 * `SalesAccess` refuses every caller today — and the adapter's own access callback asks the same question again.
 */
final class BindQuotationDocument
{
    public function __construct(private readonly BindDocumentSubject $documents) {}

    /** @return array{binding: QuotationDocumentBinding, replayed: bool} */
    public function __invoke(User $actor, int $snapshotId, string $operationKey): array
    {
        SalesAccess::require($actor, PermissionVocabulary::DOCUMENT_GENERATE, 'generate a quotation document');
        $snapshot = QuotationCustomerDocumentSnapshot::query()->find($snapshotId)
            ?? throw new DomainInvariantException('The quotation document snapshot was not found.', 'snapshot_id', 'SNAPSHOT_NOT_FOUND');

        return DB::transaction(function () use ($actor, $snapshot, $operationKey): array {
            $result = $this->documents->execute(
                new DocumentProjection(QuotationCustomerDocumentAdapter::KEY, $snapshot->schema_version, $snapshot->projection),
                QuotationCustomerDocumentAdapter::AUDIENCE, 'quotation-snapshot:'.$snapshot->id, 1, $operationKey, $actor->id,
            );
            $binding = QuotationDocumentBinding::query()->firstOrCreate(
                ['document_binding_id' => $result['binding']->id],
                ['snapshot_id' => $snapshot->id, 'audience' => QuotationCustomerDocumentAdapter::AUDIENCE],
            );

            return ['binding' => $binding, 'replayed' => $result['replayed']];
        });
    }
}
