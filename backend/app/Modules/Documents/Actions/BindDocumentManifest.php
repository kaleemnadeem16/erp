<?php

declare(strict_types=1);

namespace App\Modules\Documents\Actions;

use App\Modules\Documents\Models\DocumentGenerationIntent;
use App\Modules\Documents\Models\DocumentManifest;
use App\Modules\Documents\Models\DocumentOperationReceipt;
use App\Modules\Documents\Support\DocumentAccessGate;
use App\Modules\Documents\Support\DocumentException;
use App\Modules\Documents\Support\DocumentOperationContext;
use App\Modules\Documents\Support\DocumentProjection;
use App\Modules\Documents\Support\DocumentTypeRegistry;
use App\Modules\Documents\Support\PinnedConfiguration;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * The owner's snapshot event: freezes one document into a manifest and records the durable intent to generate it, in the owner's own transaction (the same
 * connection), so a failure after binding rolls the owner's snapshot back with it. Fails closed on the owner's access (a denial or a throw refuses, and is logged
 * without content) and on a payload outside the adapter's schema. The template and page policy are resolved at this moment and pinned as configuration, the brand
 * as NOT_AVAILABLE, assets and fonts as empty lists (none exist yet) and the renderer as unassigned (DF3 assigns it); nothing is rendered.
 *
 * Identity: one manifest per (binding, purpose, stable generation identity). A fresh key for the same identity and the same frozen content resolves the ORIGINAL
 * manifest — whatever the configuration has since become — and a different frozen content for it is a conflict, never silent reuse. A used key with a different
 * request is a conflict too. Concurrent callers serialise on the identity, so exactly one manifest and one intent are ever written.
 *
 * Internal only: no route, grant or duty. DRAFT_PREVIEW is a conspicuously draft purpose; an ORIGINAL belongs to an owner that has really issued its source.
 */
final class BindDocumentManifest
{
    public const OPERATION = 'bind_manifest';

    public const INITIAL_INTENT = 'initial';

    /** DF3 assigns the real renderer contract; until then a manifest says so rather than claim one. */
    public const RENDERER_CONTRACT = 'UNASSIGNED';

    public const RENDERER_VERSION = '0';

    public const FORMATTING = ['locale' => 'en', 'direction' => 'LTR'];

    public function __construct(
        private readonly DocumentTypeRegistry $registry,
        private readonly CompanyContext $company,
        private readonly BindDocumentSubject $subjects,
        private readonly ResolveDocumentPolicy $policy,
    ) {}

    /** @return array{manifest: DocumentManifest, intent: DocumentGenerationIntent, replayed: bool} */
    public function execute(
        DocumentProjection $projection,
        string $audience,
        string $ownerBindingIdentity,
        int $sourceGeneration,
        string $purpose,
        string $stableGenerationIdentity,
        string $operationKey,
        ?int $actorId = null,
        ?string $sourceSnapshotReference = null,
        ?int $explicitTemplateVersionId = null,
        ?int $explicitPagePolicyVersionId = null,
    ): array {
        if (! in_array($purpose, [DocumentManifest::DRAFT_PREVIEW, DocumentManifest::ORIGINAL], true)) {
            throw new DocumentException("'{$purpose}' is not a document purpose.", 'purpose', 'purpose_invalid');
        }
        $stableGenerationIdentity = trim($stableGenerationIdentity);
        $sourceSnapshotReference = $sourceSnapshotReference === null ? null : trim($sourceSnapshotReference);
        if ($stableGenerationIdentity === '' || mb_strlen($stableGenerationIdentity) > 120 || trim($operationKey) === '' || mb_strlen($operationKey) > 120
            || $sourceGeneration < 1 || trim($ownerBindingIdentity) === '' || ($sourceSnapshotReference !== null && ($sourceSnapshotReference === '' || mb_strlen($sourceSnapshotReference) > 255))) {
            throw new DocumentException('A manifest needs a source generation, an owner identity, a stable generation identity (at most 120 characters), an operation key and, if given, a snapshot reference of at most 255 characters.', null, 'manifest_invalid');
        }
        $adapter = $this->registry->get($projection->typeKey, $projection->schemaVersion);
        if (! in_array($audience, $adapter->audiences(), true)) {
            throw new DocumentException("Document type '{$projection->typeKey}' is not bound for audience '{$audience}'.", 'audience', 'audience_unsupported');
        }
        $companyId = $this->company->id();
        if (! DocumentAccessGate::allows($adapter, new DocumentOperationContext($companyId, $actorId, self::OPERATION, $projection->typeKey, $audience, $ownerBindingIdentity))) {
            throw new DocumentException("The owner of '{$projection->typeKey}' did not authorise this operation.", null, 'access_denied');
        }
        $adapter->projectionSchema()->validate($projection->payload);

        $inputDigest = PinnedConfiguration::digest([$projection->typeKey, $projection->schemaVersion, $audience, $ownerBindingIdentity, $sourceGeneration, $purpose,
            $stableGenerationIdentity, $projection->digest(), $sourceSnapshotReference]);
        $requestDigest = PinnedConfiguration::digest([$inputDigest, $explicitTemplateVersionId, $explicitPagePolicyVersionId]);

        return DB::transaction(function () use ($projection, $audience, $ownerBindingIdentity, $sourceGeneration, $purpose, $stableGenerationIdentity, $operationKey, $actorId, $sourceSnapshotReference,
            $explicitTemplateVersionId, $explicitPagePolicyVersionId, $inputDigest, $requestDigest, $companyId): array {
            // Configuration may not move underneath a freeze: activations take these locks exclusively, so a downgrade cannot slip past a manifest that is pinning the
            // version it leaves. Shared, so freezes do not queue behind one another; the identity lock below is what serialises the same document.
            foreach (['*', $projection->typeKey] as $scope) {
                DB::select('select pg_advisory_xact_lock_shared(hashtext(?))', ["documents:default:{$companyId}:{$scope}"]);
            }
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["documents:manifest:{$companyId}:{$projection->typeKey}:{$audience}:{$ownerBindingIdentity}:{$purpose}:{$stableGenerationIdentity}"]);

            $receipt = DocumentOperationReceipt::query()->where(['operation' => self::OPERATION, 'operation_key' => $operationKey])->first();
            if ($receipt !== null) {
                if ($receipt->intent_digest !== $requestDigest) {
                    throw new DocumentException("Operation key '{$operationKey}' was already used for a different request.", 'operation_key', 'key_conflict');
                }
                $manifest = DocumentManifest::query()->where(['subject_binding_id' => $receipt->binding_id, 'purpose' => $purpose, 'stable_generation_identity' => $stableGenerationIdentity])->firstOrFail();

                return ['manifest' => $manifest, 'intent' => $this->initialIntent($manifest), 'replayed' => true];
            }

            $binding = $this->subjects->execute($projection, $audience, $ownerBindingIdentity, $sourceGeneration, $operationKey.':subject', $actorId)['binding'];
            $existing = DocumentManifest::query()->where(['subject_binding_id' => $binding->id, 'purpose' => $purpose, 'stable_generation_identity' => $stableGenerationIdentity])->first();
            if ($existing !== null) {
                if ($existing->input_digest !== $inputDigest) {
                    throw new DocumentException('This document identity was already frozen with different content; a changed document needs a new generation identity.', 'stable_generation_identity', 'manifest_conflict');
                }
                $this->receipt($operationKey, $requestDigest, $binding->id, $actorId);

                return ['manifest' => $existing, 'intent' => $this->initialIntent($existing), 'replayed' => true];
            }

            $resolved = $this->policy->execute($projection->typeKey, $explicitTemplateVersionId, $explicitPagePolicyVersionId);
            $template = PinnedConfiguration::of($resolved->template);
            $page = PinnedConfiguration::of($resolved->pagePolicy);
            $pinned = [
                'input_digest' => $inputDigest, 'template' => $template, 'page_policy' => $page, 'brand_state' => $resolved->brand, 'asset_manifest' => [], 'fonts' => [],
                'renderer_contract' => self::RENDERER_CONTRACT, 'renderer_version' => self::RENDERER_VERSION, 'locale_formatting' => self::FORMATTING,
            ];
            $manifest = DocumentManifest::create([
                'subject_binding_id' => $binding->id, 'purpose' => $purpose, 'stable_generation_identity' => $stableGenerationIdentity, 'input_digest' => $inputDigest,
                'frozen_payload' => $projection->payload, 'schema_version' => $projection->schemaVersion, 'source_generation' => $sourceGeneration,
                'source_snapshot_reference' => $sourceSnapshotReference, 'resolved_template' => $template, 'resolved_page_policy' => $page,
                'template_version_id' => $resolved->template->versionId, 'page_policy_version_id' => $resolved->pagePolicy->versionId, 'brand_state' => $resolved->brand,
                'asset_manifest' => [], 'fonts' => [], 'renderer_contract' => self::RENDERER_CONTRACT, 'renderer_version' => self::RENDERER_VERSION, 'locale_formatting' => self::FORMATTING,
                'pinned_manifest_digest' => PinnedConfiguration::digest($pinned), 'initiator_id' => $actorId, 'recorded_at' => now(),
            ])->refresh();
            $intent = DocumentGenerationIntent::create([
                'manifest_id' => $manifest->id, 'operation_identity' => self::INITIAL_INTENT, 'request_key' => $operationKey, 'intent_digest' => $requestDigest, 'state' => 'PENDING',
                'fencing_generation' => 0, 'created_at' => now(), 'updated_at' => now(),
            ])->refresh();
            $this->receipt($operationKey, $requestDigest, $binding->id, $actorId);

            return ['manifest' => $manifest, 'intent' => $intent, 'replayed' => false];
        });
    }

    private function initialIntent(DocumentManifest $manifest): DocumentGenerationIntent
    {
        return DocumentGenerationIntent::query()->where(['manifest_id' => $manifest->id, 'operation_identity' => self::INITIAL_INTENT])->firstOrFail();
    }

    private function receipt(string $key, string $digest, int $bindingId, ?int $actorId): void
    {
        DocumentOperationReceipt::create(['operation' => self::OPERATION, 'operation_key' => $key, 'intent_digest' => $digest, 'binding_id' => $bindingId, 'actor_id' => $actorId, 'recorded_at' => now()]);
    }
}
