<?php

declare(strict_types=1);

namespace App\Modules\Documents\Actions;

use App\Modules\Documents\Models\DocumentOperationReceipt;
use App\Modules\Documents\Models\DocumentSubjectBinding;
use App\Modules\Documents\Support\DocumentException;
use App\Modules\Documents\Support\DocumentOperationContext;
use App\Modules\Documents\Support\DocumentProjection;
use App\Modules\Documents\Support\DocumentTypeRegistry;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Binds one registered document type to one owner subject generation, idempotently. Fails closed: an unregistered or incompatible type, an audience the type
 * does not declare, a denied or throwing access callback, or a projection outside the adapter's schema all refuse before anything is written. A fresh key for
 * the same identity resolves the original binding (no duplicate archive identity); a changed intent under a used key is a conflict.
 *
 * The owning module calls this inside its own transaction and keeps its own binding table (real FKs to this row and its subject). Internal only: DF0 has no
 * route, grant or duty.
 */
final class BindDocumentSubject
{
    public const OPERATION = 'bind';

    public function __construct(private readonly DocumentTypeRegistry $registry, private readonly CompanyContext $company) {}

    /** @return array{binding: DocumentSubjectBinding, replayed: bool} */
    public function execute(DocumentProjection $projection, string $audience, string $ownerBindingIdentity, int $sourceGeneration, string $operationKey, ?int $actorId = null): array
    {
        $adapter = $this->registry->get($projection->typeKey, $projection->schemaVersion);
        if (! in_array($audience, $adapter->audiences(), true)) {
            throw new DocumentException("Document type '{$projection->typeKey}' is not bound for audience '{$audience}'.", 'audience', 'audience_unsupported');
        }
        if ($sourceGeneration < 1 || trim($ownerBindingIdentity) === '' || trim($operationKey) === '') {
            throw new DocumentException('A binding needs a source generation, an owner identity and an operation key.', null, 'binding_invalid');
        }
        $companyId = $this->company->id();
        $context = new DocumentOperationContext($companyId, $actorId, self::OPERATION, $projection->typeKey, $audience, $ownerBindingIdentity);
        try {
            $allowed = $adapter->access()->authorise($context);
        } catch (Throwable) {
            $allowed = false;
        }
        if ($allowed !== true) {
            throw new DocumentException("The owner of '{$projection->typeKey}' did not authorise this operation.", null, 'access_denied');
        }
        $adapter->projectionSchema()->validate($projection->payload);

        $digest = hash('sha256', implode('|', [$projection->typeKey, $projection->schemaVersion, $audience, $ownerBindingIdentity, $sourceGeneration, $projection->digest()]));

        return DB::transaction(function () use ($projection, $audience, $ownerBindingIdentity, $sourceGeneration, $operationKey, $digest, $actorId, $companyId): array {
            // Serialises concurrent use of one key, so the loser replays the winner's receipt instead of failing on the unique index mid-transaction.
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["documents:{$companyId}:".self::OPERATION.":{$operationKey}"]);

            return $this->apply($projection, $audience, $ownerBindingIdentity, $sourceGeneration, $operationKey, $digest, $actorId);
        });
    }

    /** @return array{binding: DocumentSubjectBinding, replayed: bool} */
    private function apply(DocumentProjection $projection, string $audience, string $identity, int $generation, string $key, string $digest, ?int $actorId): array
    {
        $receipt = DocumentOperationReceipt::query()->where(['operation' => self::OPERATION, 'operation_key' => $key])->first();
        if ($receipt !== null) {
            if ($receipt->intent_digest !== $digest) {
                throw new DocumentException("Operation key '{$key}' was already used for a different request.", 'operation_key', 'key_conflict');
            }

            return ['binding' => $receipt->binding()->firstOrFail(), 'replayed' => true];
        }

        $binding = DocumentSubjectBinding::query()->firstOrCreate(
            ['type_key' => $projection->typeKey, 'audience' => $audience, 'owner_binding_identity' => $identity, 'source_generation' => $generation],
            ['schema_version' => $projection->schemaVersion, 'bound_by' => $actorId, 'bound_at' => now()],
        );
        DocumentOperationReceipt::create([
            'operation' => self::OPERATION, 'operation_key' => $key, 'intent_digest' => $digest,
            'binding_id' => $binding->id, 'actor_id' => $actorId, 'recorded_at' => now(),
        ]);

        return ['binding' => $binding, 'replayed' => false];
    }
}
