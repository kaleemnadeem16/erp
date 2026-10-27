<?php

declare(strict_types=1);

namespace App\Modules\Documents\Actions;

use App\Modules\Documents\Models\DocumentGenerationIntent;
use App\Modules\Documents\Models\DocumentManifest;
use App\Modules\Documents\Support\DocumentAccessGate;
use App\Modules\Documents\Support\DocumentException;
use App\Modules\Documents\Support\DocumentGenerationStatus;
use App\Modules\Documents\Support\DocumentOperationContext;
use App\Modules\Documents\Support\DocumentTypeRegistry;
use App\Support\CompanyContext;

/**
 * The safe status of a manifest's generation (one operation identity, or the latest intent): pending, running, failed, verified or missing. The owner's access is
 * asked on every read — a status is never an authorisation, and a cached answer is not one. No payload, key, path or owner identity is returned. Internal only.
 */
final class ReadDocumentGenerationStatus
{
    public const OPERATION = 'read_status';

    public function __construct(private readonly DocumentTypeRegistry $registry, private readonly CompanyContext $company) {}

    public function execute(int $manifestId, ?string $operationIdentity = null, ?int $actorId = null): DocumentGenerationStatus
    {
        $manifest = DocumentManifest::query()->with('binding')->find($manifestId) ?? throw new DocumentException('The document manifest was not found.', 'manifest_id', 'manifest_not_found');
        $binding = $manifest->binding;
        $adapter = $this->registry->get($binding->type_key, $binding->schema_version);
        if (! DocumentAccessGate::allows($adapter, new DocumentOperationContext($this->company->id(), $actorId, self::OPERATION, $binding->type_key, $binding->audience, $binding->owner_binding_identity))) {
            throw new DocumentException("The owner of '{$binding->type_key}' did not authorise this operation.", null, 'access_denied');
        }
        $query = DocumentGenerationIntent::query()->where('manifest_id', $manifest->id);
        $intent = $operationIdentity === null ? $query->orderByDesc('id')->first() : $query->where('operation_identity', $operationIdentity)->first();
        if ($intent === null) {
            return new DocumentGenerationStatus($manifest->id, $manifest->purpose, $operationIdentity ?? '', DocumentGenerationStatus::MISSING, null);
        }

        return new DocumentGenerationStatus($manifest->id, $manifest->purpose, $intent->operation_identity, DocumentGenerationStatus::fromState($intent->state), $intent->error_code);
    }
}
