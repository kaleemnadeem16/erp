<?php

declare(strict_types=1);

namespace App\Modules\Documents\Actions;

use App\Modules\Documents\Models\DocumentArtefact;
use App\Modules\Documents\Models\DocumentArtefactHead;
use App\Modules\Documents\Models\DocumentGenerationIntent;
use App\Modules\Documents\Models\DocumentManifest;
use App\Modules\Documents\Models\DocumentOperationReceipt;
use App\Modules\Documents\Support\DocumentAccessGate;
use App\Modules\Documents\Support\DocumentArtefactFileOwner;
use App\Modules\Documents\Support\DocumentException;
use App\Modules\Documents\Support\DocumentOperationContext;
use App\Modules\Documents\Support\DocumentTypeRegistry;
use App\Modules\Documents\Support\ProjectionSchema;
use App\Modules\Documents\Support\Render\DocumentRenderer;
use App\Modules\Documents\Support\Render\RenderedDocument;
use App\Modules\Documents\Support\Render\RenderInput;
use App\Modules\Files\Actions\RequestFileUpload;
use App\Modules\Files\Actions\StageUploadedFile;
use App\Modules\Files\Actions\VerifyStagedFile;
use App\Modules\Files\Models\FileBlob;
use App\Modules\Files\Support\FileException;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The generation worker: turns one PENDING generation intent into a stored, verified artefact — exactly once.
 *
 *  1. The document owner is asked (`generate`, with the frozen purpose and source), then the intent is CLAIMED in a short transaction: PENDING (or RUNNING with an expired
 *     lease) becomes RUNNING with a new fencing generation and a database-clock lease. A live lease held by someone else is refused; a VERIFIED intent just returns its
 *     artefact (a lost response replays).
 *  2. The bytes are rendered OUTSIDE any transaction from the manifest alone — the renderer it pinned, its frozen payload, template and page policy — and stored through the
 *     Files kernel (request, stage, verify) under the Documents file owner, so the object is validated, opaque-keyed and verified before it can be an artefact.
 *  3. A short fenced transaction publishes the artefact, the head and the VERIFIED intent together. The database refuses an artefact from any worker that no longer holds the
 *     running intent at its fence, so a worker whose lease was taken over cannot overwrite the authoritative result (its file is an orphan for reconciliation).
 *
 * Any failure after the claim marks the intent FAILED with a stable code while this worker still holds it; a retry is a new intent (RequestDocumentGeneration). A manifest
 * that pinned no renderer is FAILED `manifest_not_renderable`, never rendered with whichever renderer is current. Internal: no route, grant or duty.
 */
final class GenerateDocumentArtefact
{
    public const OPERATION = 'generate_artefact';

    public const LEASE_SECONDS = 300;

    public function __construct(
        private readonly DocumentTypeRegistry $registry,
        private readonly CompanyContext $company,
        private readonly DocumentRenderer $renderer,
        private readonly RequestFileUpload $uploads,
        private readonly StageUploadedFile $stage,
        private readonly VerifyStagedFile $verify,
    ) {}

    /** @return array{artefact: DocumentArtefact, replayed: bool} */
    public function execute(int $intentId, ?int $actorId = null): array
    {
        $intent = DocumentGenerationIntent::query()->find($intentId) ?? throw new DocumentException('The generation request was not found.', 'intent_id', 'intent_not_found');
        $manifest = DocumentManifest::query()->with('binding')->findOrFail($intent->manifest_id);
        $binding = $manifest->binding;
        $adapter = $this->registry->get($binding->type_key, $binding->schema_version);
        if (! DocumentAccessGate::allows($adapter, new DocumentOperationContext($this->company->id(), $actorId, 'generate', $binding->type_key, $binding->audience, $binding->owner_binding_identity,
            $manifest->purpose, $manifest->source_generation, $manifest->source_snapshot_reference, $manifest->stable_generation_identity))) {
            throw new DocumentException("The owner of '{$binding->type_key}' did not authorise this operation.", null, 'access_denied');
        }

        if ($intent->state === 'VERIFIED') {
            return ['artefact' => DocumentArtefact::query()->where('intent_id', $intent->id)->firstOrFail(), 'replayed' => true];
        }
        if (! $manifest->isRenderable() || $manifest->renderer_contract !== $this->renderer->contract() || $manifest->renderer_version !== $this->renderer->version()) {
            $this->failUnclaimed($intent->id, 'manifest_not_renderable');
            throw new DocumentException('This freeze did not pin the renderer that is available, so it cannot be rendered.', 'manifest_id', 'manifest_not_renderable');
        }

        $fence = $this->claim($intent->id);
        if ($fence === null) {
            return ['artefact' => DocumentArtefact::query()->where('intent_id', $intent->id)->firstOrFail(), 'replayed' => true];
        }

        try {
            $rendered = $this->render($manifest, $adapter->projectionSchema(), $binding->type_key);
            $blob = $this->store($manifest, $rendered, $fence, $actorId ?? $manifest->initiator_id);

            return DB::transaction(fn (): array => $this->publish($intent->id, $manifest, $blob, $rendered, $fence));
        } catch (DocumentException $e) {
            $this->fail($intent->id, $fence, (string) $e->reasonCode);
            throw $e;
        } catch (FileException $e) {
            $this->fail($intent->id, $fence, 'storage_'.$e->reasonCode);
            throw new DocumentException('The generated document could not be stored.', null, 'storage_failed');
        } catch (Throwable $e) {
            $this->fail($intent->id, $fence, 'render_failed');
            throw new DocumentException('The document could not be generated.', null, 'render_failed');
        }
    }

    /** @return int|null the fence this worker now holds, or null when the intent was already completed */
    private function claim(int $intentId): ?int
    {
        return DB::transaction(function () use ($intentId): ?int {
            $locked = DocumentGenerationIntent::query()->whereKey($intentId)->lockForUpdate()->firstOrFail();
            if ($locked->state === 'VERIFIED') {
                return null;
            }
            if ($locked->state === 'FAILED') {
                throw new DocumentException('That generation failed; request a retry for a new one.', 'intent_id', 'generation_failed');
            }
            if ($locked->state === 'RUNNING' && (bool) DB::selectOne('select ?::timestamptz > clock_timestamp() as live', [$locked->getRawOriginal('lease_expiry')])->live) {
                throw new DocumentException('Another worker holds this generation.', 'intent_id', 'generation_in_progress');
            }
            $lease = DB::selectOne("select clock_timestamp() + (? * interval '1 second') as lease", [self::LEASE_SECONDS])->lease;
            $locked->update(['state' => 'RUNNING', 'fencing_generation' => $locked->fencing_generation + 1, 'lease_expiry' => $lease, 'error_code' => null]);

            return $locked->fencing_generation;
        });
    }

    private function render(DocumentManifest $manifest, ProjectionSchema $schema, string $typeKey): RenderedDocument
    {
        $parts = explode('.', $typeKey);
        $title = ucfirst(str_replace('-', ' ', $parts[1] ?? $parts[0]));

        return $this->renderer->render(new RenderInput($title, $manifest->purpose, $manifest->frozen_payload, $schema, $manifest->resolved_template, $manifest->resolved_page_policy,
            $manifest->recorded_at, $manifest->pinned_manifest_digest));
    }

    private function store(DocumentManifest $manifest, RenderedDocument $rendered, int $fence, ?int $actorId): FileBlob
    {
        if ($actorId === null) {
            throw new DocumentException('A generation needs an actor on record to store its file under.', null, 'no_actor');
        }
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $rendered->bytes);
        rewind($stream);
        $identity = 'manifest:'.$manifest->id;
        $request = $this->uploads->execute(DocumentArtefactFileOwner::OWNER, $identity, DocumentArtefactFileOwner::PURPOSE, strlen($rendered->bytes), 'docgen:'.$manifest->id.':'.$fence, $actorId, $rendered->sha256);
        $staged = $this->stage->execute($request['intent']->id, $stream, 'document.pdf', 'application/pdf', $actorId);
        $verified = $this->verify->execute($request['intent']->id, $actorId)['blob'];
        if ($verified->health !== 'VERIFIED' || $staged['blob']->id !== $verified->id) {
            throw new DocumentException('The generated file did not verify.', null, 'storage_unverified');
        }

        return $verified;
    }

    /** @return array{artefact: DocumentArtefact, replayed: bool} */
    private function publish(int $intentId, DocumentManifest $manifest, FileBlob $blob, RenderedDocument $rendered, int $fence): array
    {
        $locked = DocumentGenerationIntent::query()->whereKey($intentId)->lockForUpdate()->firstOrFail();
        if ($locked->state !== 'RUNNING' || $locked->fencing_generation !== $fence) {
            throw new DocumentException('This worker no longer holds the generation; its result is discarded.', 'intent_id', 'lease_lost');
        }
        $artefact = DocumentArtefact::create([
            'manifest_id' => $manifest->id, 'intent_id' => $intentId, 'fence' => $fence, 'blob_id' => $blob->id, 'purpose' => $manifest->purpose, 'media_type' => 'application/pdf',
            'sha256' => $rendered->sha256, 'size_bytes' => strlen($rendered->bytes), 'page_count' => $rendered->pages, 'renderer_contract' => $manifest->renderer_contract,
            'renderer_version' => $manifest->renderer_version, 'created_at' => now(),
        ])->refresh();
        $head = DocumentArtefactHead::query()->where(['subject_binding_id' => $manifest->subject_binding_id, 'purpose' => $manifest->purpose])->lockForUpdate()->first();
        if ($head === null) {
            DocumentArtefactHead::create(['subject_binding_id' => $manifest->subject_binding_id, 'purpose' => $manifest->purpose, 'artefact_id' => $artefact->id, 'generation' => 1, 'updated_at' => now()]);
        } else {
            $head->update(['artefact_id' => $artefact->id, 'generation' => $head->generation + 1]);
        }
        $locked->update(['state' => 'VERIFIED', 'lease_expiry' => null, 'error_code' => null]);
        DocumentOperationReceipt::create(['operation' => self::OPERATION, 'operation_key' => 'generate:'.$intentId.':'.$fence, 'intent_digest' => $rendered->sha256, 'binding_id' => $manifest->subject_binding_id,
            'actor_id' => null, 'recorded_at' => now()]);

        return ['artefact' => $artefact, 'replayed' => false];
    }

    /** The worker that still holds the intent at its fence marks it FAILED; one that has lost it changes nothing. */
    private function fail(int $intentId, int $fence, string $code): void
    {
        try {
            DB::transaction(function () use ($intentId, $fence, $code): void {
                $locked = DocumentGenerationIntent::query()->whereKey($intentId)->lockForUpdate()->first();
                if ($locked !== null && $locked->state === 'RUNNING' && $locked->fencing_generation === $fence) {
                    $locked->update(['state' => 'FAILED', 'lease_expiry' => null, 'error_code' => mb_substr($code, 0, 60)]);
                }
            });
        } catch (Throwable) {
            // The lease expires on its own; a failure to record a failure must not mask the real one.
        }
    }

    private function failUnclaimed(int $intentId, string $code): void
    {
        try {
            DB::transaction(function () use ($intentId, $code): void {
                $locked = DocumentGenerationIntent::query()->whereKey($intentId)->lockForUpdate()->first();
                if ($locked !== null && $locked->state === 'PENDING') {
                    $locked->update(['state' => 'FAILED', 'error_code' => $code]);
                }
            });
        } catch (Throwable) {
        }
    }
}
