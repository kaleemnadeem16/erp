<?php

declare(strict_types=1);

namespace App\Modules\Files\Actions;

use App\Modules\Files\Models\FileOperationReceipt;
use App\Modules\Files\Models\FileUploadIntent;
use App\Modules\Files\Support\FileAccessGate;
use App\Modules\Files\Support\FileException;
use App\Modules\Files\Support\FileOperationContext;
use App\Modules\Files\Support\FileOwnerRegistry;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * Records the durable intent to upload one file for an owner's purpose. Fails closed on an unknown owner or purpose, a size over the purpose's ceiling and the owner's
 * access (a denial or a throw refuses, and is logged without content). Idempotent on the caller's key: the same key with the same request returns the original intent,
 * a different request under it is a conflict. Nothing is stored and no byte is read; the intent only expires if nothing is staged. Internal: no route, grant or duty.
 */
final class RequestFileUpload
{
    public const OPERATION = 'request_upload';

    public function __construct(private readonly FileOwnerRegistry $registry, private readonly CompanyContext $company) {}

    /** @return array{intent: FileUploadIntent, replayed: bool} */
    public function execute(string $ownerTypeKey, string $ownerBindingIdentity, string $purposeKey, int $expectedSize, string $idempotencyKey, int $actorId, ?string $expectedSha256 = null, int $ttlMinutes = 30): array
    {
        $ownerBindingIdentity = trim($ownerBindingIdentity);
        $idempotencyKey = trim($idempotencyKey);
        if ($ownerBindingIdentity === '' || mb_strlen($ownerBindingIdentity) > 120 || $idempotencyKey === '' || mb_strlen($idempotencyKey) > 120 || $expectedSize < 1 || $ttlMinutes < 1 || $ttlMinutes > 1440
            || ($expectedSha256 !== null && preg_match('/^[0-9a-f]{64}$/', $expectedSha256) !== 1)) {
            throw new FileException('An upload request needs an owner identity and an idempotency key of at most 120 characters, a positive size and, if given, a lowercase SHA-256.', null, 'request_invalid');
        }
        $adapter = $this->registry->get($ownerTypeKey);
        $purpose = $this->registry->purpose($ownerTypeKey, $purposeKey);
        $companyId = $this->company->id();
        if (! FileAccessGate::allows($adapter, new FileOperationContext($companyId, $actorId, 'upload', $ownerTypeKey, $purposeKey, $ownerBindingIdentity))) {
            throw new FileException("The owner of '{$ownerTypeKey}' did not authorise this operation.", null, 'access_denied');
        }
        if ($expectedSize > $purpose->maxBytes) {
            throw new FileException("The file is larger than the {$purpose->maxBytes} bytes this purpose accepts.", 'file', 'size_exceeded');
        }
        $digest = hash('sha256', json_encode([$ownerTypeKey, $ownerBindingIdentity, $purposeKey, $expectedSize, $expectedSha256, $actorId], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($ownerTypeKey, $ownerBindingIdentity, $purposeKey, $expectedSize, $idempotencyKey, $actorId, $expectedSha256, $ttlMinutes, $digest, $companyId): array {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["files:intent:{$companyId}:{$idempotencyKey}"]);
            $existing = FileUploadIntent::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                if ($existing->intent_digest !== $digest) {
                    throw new FileException("Idempotency key '{$idempotencyKey}' was already used for a different request.", 'idempotency_key', 'key_conflict');
                }

                return ['intent' => $existing, 'replayed' => true];
            }
            $intent = FileUploadIntent::create([
                'owner_type_key' => $ownerTypeKey, 'owner_binding_identity' => $ownerBindingIdentity, 'purpose_key' => $purposeKey, 'actor_id' => $actorId, 'idempotency_key' => $idempotencyKey,
                'intent_digest' => $digest, 'expected_size' => $expectedSize, 'expected_sha256' => $expectedSha256, 'state' => 'PENDING', 'expires_at' => now()->addMinutes($ttlMinutes),
                'created_at' => now(), 'updated_at' => now(),
            ])->refresh();
            FileOperationReceipt::create(['operation' => self::OPERATION, 'operation_key' => $idempotencyKey, 'intent_digest' => $digest, 'intent_id' => $intent->id, 'actor_id' => $actorId, 'recorded_at' => now()]);

            return ['intent' => $intent, 'replayed' => false];
        });
    }
}
