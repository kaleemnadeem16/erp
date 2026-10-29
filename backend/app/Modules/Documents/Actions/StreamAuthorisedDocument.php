<?php

declare(strict_types=1);

namespace App\Modules\Documents\Actions;

use App\Modules\Documents\Models\DocumentArtefact;
use App\Modules\Documents\Support\DocumentAccessGate;
use App\Modules\Documents\Support\DocumentDownload;
use App\Modules\Documents\Support\DocumentException;
use App\Modules\Documents\Support\DocumentOperationContext;
use App\Modules\Documents\Support\DocumentTypeRegistry;
use App\Modules\Files\Models\FileBlob;
use App\Modules\Files\Support\StoredObject;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\Storage;

/**
 * Secure delivery of one stored original or preview: the document owner decides (`download`, under its own duty and scope), the Files blob must be VERIFIED, and the bytes are
 * hash-checked before a single one is handed on. A missing or corrupt object is reported as unavailable (never served, never regenerated in its place). The result carries a
 * generated attachment name and fixed headers; no storage path, key or URL ever leaves. Internal: no route yet.
 */
final class StreamAuthorisedDocument
{
    public function __construct(private readonly DocumentTypeRegistry $registry, private readonly CompanyContext $company) {}

    public function execute(int $artefactId, ?int $actorId = null): DocumentDownload
    {
        $artefact = DocumentArtefact::query()->find($artefactId) ?? throw new DocumentException('The document was not found.', 'artefact_id', 'artefact_not_found');
        $manifest = $artefact->manifest;
        $binding = $manifest->binding()->firstOrFail();
        $adapter = $this->registry->get($binding->type_key, $binding->schema_version);
        if (! DocumentAccessGate::allows($adapter, new DocumentOperationContext($this->company->id(), $actorId, 'download', $binding->type_key, $binding->audience, $binding->owner_binding_identity,
            $manifest->purpose, $manifest->source_generation, $manifest->source_snapshot_reference, $manifest->stable_generation_identity))) {
            throw new DocumentException("The owner of '{$binding->type_key}' did not authorise this operation.", null, 'access_denied');
        }
        $blob = FileBlob::query()->find($artefact->blob_id);
        if ($blob === null || $blob->health !== 'VERIFIED' || $blob->sha256 !== $artefact->sha256 || $blob->size_bytes !== $artefact->size_bytes) {
            throw new DocumentException('This document is not available.', 'artefact_id', 'artefact_unavailable');
        }
        $path = StoredObject::path($blob->storage_key);
        $observed = StoredObject::observe($blob->storage_disk, $path);
        if ($observed === null || $observed['sha256'] !== $artefact->sha256 || $observed['size'] !== $artefact->size_bytes) {
            throw new DocumentException('This document is not available.', 'artefact_id', 'artefact_unavailable');
        }
        $stream = Storage::disk($blob->storage_disk)->readStream($path);
        if ($stream === null) {
            throw new DocumentException('This document is not available.', 'artefact_id', 'artefact_unavailable');
        }
        $name = strtolower($manifest->purpose === 'ORIGINAL' ? 'document' : 'draft').'-'.$artefact->id.'.pdf';

        return new DocumentDownload($stream, $name, 'application/pdf', $artefact->size_bytes, $artefact->sha256);
    }
}
