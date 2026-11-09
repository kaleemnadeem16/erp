<?php

declare(strict_types=1);

namespace App\Modules\Documents\Actions;

use App\Modules\Documents\Models\DocumentGenerationIntent;
use App\Modules\Documents\Models\DocumentManifest;
use App\Modules\Documents\Models\DocumentOperationReceipt;
use App\Modules\Documents\Support\DocumentAccessGate;
use App\Modules\Documents\Support\DocumentException;
use App\Modules\Documents\Support\DocumentOperationContext;
use App\Modules\Documents\Support\DocumentTypeRegistry;
use App\Modules\Documents\Support\PinnedConfiguration;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * Records another durable intent to generate an existing manifest — a retry after a failure — under a new operation identity. The owner's access is asked again
 * for this operation. A manifest already has one intent from its freeze, so a further one is accepted only when every earlier intent has FAILED: a pending, running
 * or verified generation is never duplicated. The same operation identity resolves the same intent; a used key with a different request is a conflict. Nothing is
 * rendered; DF3 owns the transitions after PENDING. Internal only.
 */
final class RequestDocumentGeneration
{
    public const OPERATION = 'request_generation';

    public function __construct(private readonly DocumentTypeRegistry $registry, private readonly CompanyContext $company) {}

    /** @return array{intent: DocumentGenerationIntent, replayed: bool} */
    public function execute(int $manifestId, string $operationIdentity, string $operationKey, ?int $actorId = null): array
    {
        $operationIdentity = trim($operationIdentity);
        if ($operationIdentity === '' || mb_strlen($operationIdentity) > 120 || trim($operationKey) === '' || mb_strlen($operationKey) > 120) {
            throw new DocumentException('A generation request needs an operation identity and an operation key of at most 120 characters.', null, 'request_invalid');
        }
        $manifest = DocumentManifest::query()->with('binding')->find($manifestId) ?? throw new DocumentException('The document manifest was not found.', 'manifest_id', 'manifest_not_found');
        $binding = $manifest->binding;
        $adapter = $this->registry->get($binding->type_key, $binding->schema_version);
        $companyId = $this->company->id();
        if (! DocumentAccessGate::allows($adapter, new DocumentOperationContext($companyId, $actorId, self::OPERATION, $binding->type_key, $binding->audience, $binding->owner_binding_identity))) {
            throw new DocumentException("The owner of '{$binding->type_key}' did not authorise this operation.", null, 'access_denied');
        }
        $digest = PinnedConfiguration::digest([$manifest->id, $operationIdentity]);

        return DB::transaction(function () use ($manifest, $operationIdentity, $operationKey, $actorId, $digest, $companyId): array {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["documents:generation:{$companyId}:{$manifest->id}"]);

            $receipt = DocumentOperationReceipt::query()->where(['operation' => self::OPERATION, 'operation_key' => $operationKey])->first();
            if ($receipt !== null && $receipt->intent_digest !== $digest) {
                throw new DocumentException("Operation key '{$operationKey}' was already used for a different request.", 'operation_key', 'key_conflict');
            }
            $existing = DocumentGenerationIntent::query()->where(['manifest_id' => $manifest->id, 'operation_identity' => $operationIdentity])->first();
            if ($receipt !== null && $existing !== null) {
                return ['intent' => $existing, 'replayed' => true];
            }
            $created = $existing === null;
            if ($existing === null) {
                if (DocumentGenerationIntent::query()->where('manifest_id', $manifest->id)->where('state', '<>', 'FAILED')->exists()) {
                    throw new DocumentException('This document already has a generation that is pending, running or verified; only a failed one can be retried.', 'operation_identity', 'generation_active');
                }
                $existing = DocumentGenerationIntent::create([
                    'manifest_id' => $manifest->id, 'operation_identity' => $operationIdentity, 'request_key' => $operationKey, 'intent_digest' => $digest, 'state' => 'PENDING',
                    'fencing_generation' => 0, 'created_at' => now(), 'updated_at' => now(),
                ])->refresh();
            }
            if ($receipt === null) {
                DocumentOperationReceipt::create(['operation' => self::OPERATION, 'operation_key' => $operationKey, 'intent_digest' => $digest, 'binding_id' => $manifest->subject_binding_id,
                    'actor_id' => $actorId, 'recorded_at' => now()]);
            }

            return ['intent' => $existing, 'replayed' => ! $created];
        });
    }

    /**
     * Records that this operation key belongs to this manifest and identity, or refuses it when an earlier request used it for something else. A caller whose document is
     * already generated, or still being generated, never reaches {@see execute()}, yet the key must still mean one request only: a reused key would otherwise silently return an
     * unrelated result instead of the documented conflict. A replay of the same request passes without writing again. {@see execute()} accepts a key recorded here.
     */
    public function claimKey(int $manifestId, string $operationIdentity, string $operationKey, ?int $actorId = null): void
    {
        $manifest = DocumentManifest::query()->find($manifestId) ?? throw new DocumentException('The document manifest was not found.', 'manifest_id', 'manifest_not_found');
        $digest = PinnedConfiguration::digest([$manifest->id, trim($operationIdentity)]);
        $companyId = $this->company->id();
        DB::transaction(function () use ($manifest, $operationKey, $digest, $actorId, $companyId): void {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["documents:generation:{$companyId}:{$manifest->id}"]);
            $receipt = DocumentOperationReceipt::query()->where(['operation' => self::OPERATION, 'operation_key' => $operationKey])->first();
            if ($receipt === null) {
                DocumentOperationReceipt::create(['operation' => self::OPERATION, 'operation_key' => $operationKey, 'intent_digest' => $digest, 'binding_id' => $manifest->subject_binding_id,
                    'actor_id' => $actorId, 'recorded_at' => now()]);
            } elseif ($receipt->intent_digest !== $digest) {
                throw new DocumentException("Operation key '{$operationKey}' was already used for a different request.", 'operation_key', 'key_conflict');
            }
        });
    }
}
