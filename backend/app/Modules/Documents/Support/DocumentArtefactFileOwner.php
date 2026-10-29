<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

use App\Models\User;
use App\Modules\Administration\Support\MemberStatus;
use App\Modules\Documents\Models\DocumentManifest;
use App\Modules\Files\Support\FileAccess;
use App\Modules\Files\Support\FileOperationContext;
use App\Modules\Files\Support\FileOwnerAdapter;
use App\Modules\Files\Support\FilePurpose;
use App\Support\CompanyContext;

/**
 * Documents as an owner of files in the shared Files store (ADR 0153): its only purpose is the generated PDF, produced inside the company by the renderer (never supplied
 * from outside). Every Files operation on such a file is authorised here by the document owner's own decision for the current actor, never by existence alone.
 */
final class DocumentArtefactFileOwner implements FileAccess, FileOwnerAdapter
{
    public const OWNER = 'documents.artefact';

    public const PURPOSE = 'documents.artefact-pdf';

    public const MAX_BYTES = 20_971_520;

    public function ownerTypeKey(): string
    {
        return self::OWNER;
    }

    public function purposes(): array
    {
        return [new FilePurpose(self::PURPOSE, ['application/pdf' => ['pdf']], self::MAX_BYTES, false)];
    }

    public function access(): FileAccess
    {
        return $this;
    }

    /** The Documents operation (asked of the document owner's own adapter) that each Files operation stands for. */
    private const DOCUMENT_OPERATION = ['upload' => 'generate', 'stage' => 'generate', 'verify' => 'generate', 'reconcile' => 'reconcile_artefact', 'restore' => 'restore_artefact'];

    /**
     * Files never decides alone, and neither does existence: the caller must be a currently active member of THIS company, the identity must name a manifest of this
     * company, and the document owner's own access decision for the matching Documents operation (the same gate the guarded Documents Actions use, with the frozen
     * purpose, source generation and snapshot reference) must be an explicit yes. A missing actor, an inactive or foreign member, an unknown identity or any other
     * operation is denied, so a direct call to a Files Action cannot do what the Documents Action would refuse.
     */
    public function authorise(FileOperationContext $context): bool
    {
        $operation = self::DOCUMENT_OPERATION[$context->operation] ?? null;
        if ($operation === null || $context->ownerTypeKey !== self::OWNER || $context->purposeKey !== self::PURPOSE || $context->actorId === null
            || $context->companyId !== app(CompanyContext::class)->id()) {
            return false;
        }
        $member = User::query()->where('company_id', $context->companyId)->whereKey($context->actorId)->first();
        if ($member === null || $member->status !== MemberStatus::Active) {
            return false;
        }
        if (preg_match('/^manifest:([1-9]\d{0,17})$/', $context->ownerBindingIdentity, $m) !== 1) {
            return false;
        }
        $manifest = DocumentManifest::query()->find((int) $m[1]);
        $binding = $manifest?->binding()->first();
        if ($manifest === null || $binding === null || $manifest->company_id !== $context->companyId) {
            return false;
        }
        $adapter = app(DocumentTypeRegistry::class)->get($binding->type_key, $binding->schema_version);

        return DocumentAccessGate::allows($adapter, new DocumentOperationContext($context->companyId, $context->actorId, $operation, $binding->type_key, $binding->audience,
            $binding->owner_binding_identity, $manifest->purpose, $manifest->source_generation, $manifest->source_snapshot_reference, $manifest->stable_generation_identity));
    }
}
