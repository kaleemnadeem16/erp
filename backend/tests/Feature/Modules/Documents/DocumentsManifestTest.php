<?php

namespace Tests\Feature\Modules\Documents;

use App\Models\User;
use App\Modules\Documents\Actions\ActivateDocumentConfiguration;
use App\Modules\Documents\Actions\BindDocumentManifest;
use App\Modules\Documents\Actions\ReadDocumentGenerationStatus;
use App\Modules\Documents\Actions\RequestDocumentGeneration;
use App\Modules\Documents\Actions\VersionDocumentPagePolicy;
use App\Modules\Documents\Actions\VersionDocumentTemplate;
use App\Modules\Documents\Models\DocumentGenerationIntent;
use App\Modules\Documents\Models\DocumentManifest;
use App\Modules\Documents\Models\DocumentOperationReceipt;
use App\Modules\Documents\Models\DocumentSubjectBinding;
use App\Modules\Documents\Support\DocumentConfigurationUsage;
use App\Modules\Documents\Support\DocumentException;
use App\Modules\Documents\Support\DocumentGenerationStatus;
use App\Modules\Documents\Support\DocumentLayouts;
use App\Modules\Documents\Support\DocumentProjection;
use App\Modules\Documents\Support\DocumentTypeRegistry;
use App\Modules\Documents\Support\ManifestConfigurationUsage;
use App\Modules\Documents\Support\PageBandMode;
use App\Modules\Documents\Support\PageFormat;
use App\Modules\Documents\Support\PageOrientation;
use App\Modules\Documents\Support\PagePolicyConfiguration;
use App\Modules\Documents\Support\TemplateConfiguration;
use App\Support\PermissionRegistry;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\Documents\CountSheetAdapter;
use Tests\Support\Documents\PurchaseOrderShapedAdapter;
use Tests\TestCase;

/**
 * DF2 — freezing a document into a manifest and recording the durable generation intent: what is pinned, the identity rules, fail-closed access, immutable evidence
 * with the database's own clock, the intent state machine, the pinned-configuration downgrade check and the database-enforced activation chain. Internal only: no
 * route, grant or duty. A test transaction never commits, so "commit" here is SET CONSTRAINTS ALL IMMEDIATE; DocumentsManifestCommitTest proves the real COMMIT.
 */
class DocumentsManifestTest extends TestCase
{
    use RefreshDatabase;

    private const PO = PurchaseOrderShapedAdapter::KEY;

    private User $user;

    private DocumentTypeRegistry $registry;

    private int $otherCompany;

    private int $keys = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->registry = new DocumentTypeRegistry;
        $this->registry->register(new PurchaseOrderShapedAdapter(supportsLetter: true));
        $this->app->instance(DocumentTypeRegistry::class, $this->registry);
        $this->otherCompany = DB::table('companies')->insertGetId(['name' => 'Other Co', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function key(): string
    {
        return 'key-'.++$this->keys;
    }

    /** @return array{manifest: DocumentManifest, intent: DocumentGenerationIntent, replayed: bool} */
    private function bind(?DocumentProjection $projection = null, string $identity = 'gen-1', ?string $key = null, string $purpose = DocumentManifest::ORIGINAL, string $subject = 'po:1', int $generation = 1,
        ?string $reference = null, ?int $template = null, ?int $page = null): array
    {
        return app(BindDocumentManifest::class)->execute($projection ?? PurchaseOrderShapedAdapter::projection(), 'SUPPLIER', $subject, $generation, $purpose, $identity, $key ?? $this->key(),
            $this->user->id, $reference, $template, $page);
    }

    private function refusal(Closure $call): string
    {
        try {
            $call();
        } catch (DocumentException $e) {
            return (string) $e->reasonCode;
        }
        $this->fail('Expected a DocumentException.');
    }

    private function assertRefused(Closure $write, string $contains): void
    {
        try {
            DB::transaction(function () use ($write): void {
                $write();
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
        } catch (QueryException $e) {
            $this->assertTrue(collect(explode('|', $contains))->contains(fn (string $needle) => str_contains($e->getMessage(), $needle)), "Expected one of [{$contains}] in: ".$e->getMessage());

            return;
        } finally {
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
        }
        $this->fail("The database accepted a write that should have been refused ({$contains}).");
    }

    private function template(string $key = 'standard'): int
    {
        return app(VersionDocumentTemplate::class)->execute(self::PO, $key, new TemplateConfiguration(DocumentLayouts::STANDARD, ['lines', 'terms'], ['lines']), $this->user->id)->id;
    }

    private function page(string $key = 'plain'): int
    {
        $c = new PagePolicyConfiguration(PageFormat::A4, PageOrientation::Portrait, '20', '20', '20', '20', PageBandMode::Full, PageBandMode::Compact, PageBandMode::PageNumberOfTotal, PageBandMode::PageNumber,
            [DocumentLayouts::STANDARD, DocumentLayouts::COMPACT]);

        return app(VersionDocumentPagePolicy::class)->execute('*', $key, $c, $this->user->id)->id;
    }

    private function activate(?int $template, ?int $page, int $expected, ?string $reason = null, string $type = self::PO): void
    {
        app(ActivateDocumentConfiguration::class)->execute($type, $template, $page, $expected, $this->user->id, $reason);
    }

    private function counts(): array
    {
        return array_map(fn (string $t) => DB::table($t)->count(), ['document_subject_bindings', 'document_manifests', 'document_generation_intents', 'document_operation_receipts']);
    }

    // ---- what is frozen ------------------------------------------------------------------------------------------------------------------------------

    public function test_a_freeze_pins_the_payload_policy_and_declared_inputs_and_records_a_pending_intent(): void
    {
        $result = $this->bind(reference: 'po-snapshot:1');
        $manifest = $result['manifest'];
        $intent = $result['intent'];

        $this->assertFalse($result['replayed']);
        $this->assertEquals(PurchaseOrderShapedAdapter::projection()->payload, $manifest->frozen_payload, 'the payload is frozen as given (jsonb reorders keys, not facts)');
        $this->assertSame([1, 1, 'po-snapshot:1', DocumentManifest::ORIGINAL, 'gen-1', $this->user->id], [$manifest->schema_version, $manifest->source_generation, $manifest->source_snapshot_reference,
            $manifest->purpose, $manifest->stable_generation_identity, $manifest->initiator_id]);
        // Nothing is configured, so the shipped defaults are pinned as configuration (no row to point at), the brand is honestly absent and nothing is assumed about assets or the renderer.
        $this->assertSame(['SHIPPED', null], [$manifest->resolved_template['source'], $manifest->template_version_id]);
        $this->assertSame(['SHIPPED', null], [$manifest->resolved_page_policy['source'], $manifest->page_policy_version_id]);
        $this->assertArrayHasKey('layout_id', $manifest->resolved_template['configuration']);
        $this->assertArrayHasKey('compatible_layouts', $manifest->resolved_page_policy['configuration']);
        $this->assertSame(['NOT_AVAILABLE', [], [], 'UNASSIGNED', '0', ['locale' => 'en', 'direction' => 'LTR']],
            [$manifest->brand_state, $manifest->asset_manifest, $manifest->fonts, $manifest->renderer_contract, $manifest->renderer_version, $manifest->locale_formatting]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $manifest->input_digest);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $manifest->pinned_manifest_digest);
        $this->assertNotSame($manifest->input_digest, $manifest->pinned_manifest_digest);
        $this->assertSame(['PENDING', 0, null, null, 'initial'], [$intent->state, $intent->fencing_generation, $intent->lease_expiry, $intent->error_code, $intent->operation_identity]);
        $this->assertSame([1, 1, 1, 2], [DocumentSubjectBinding::count(), DocumentManifest::count(), DocumentGenerationIntent::count(), DocumentOperationReceipt::count()], 'binding, manifest, intent, and a receipt for the subject bind and the manifest bind');
        $this->assertSame($manifest->subject_binding_id, DocumentSubjectBinding::query()->sole()->id);
    }

    public function test_configured_versions_are_pinned_as_configuration_and_later_changes_cannot_alter_the_manifest(): void
    {
        $t1 = $this->template();
        $p1 = $this->page();
        $this->activate($t1, $p1, 0);
        $first = $this->bind()['manifest'];

        $this->assertSame([$t1, $p1, 'TYPE', 'TYPE'], [$first->template_version_id, $first->page_policy_version_id, $first->resolved_template['source'], $first->resolved_page_policy['source']]);
        $pinnedDigest = $first->pinned_manifest_digest;

        // The Company moves to newer versions; the frozen manifest still says what it was made with, and a fresh key resolves the ORIGINAL.
        $t2 = $this->template();
        $this->activate($t2, null, 1);
        $again = $this->bind(key: $this->key());

        $this->assertTrue($again['replayed']);
        $this->assertSame($first->id, $again['manifest']->id);
        $this->assertSame([$t1, $pinnedDigest], [$again['manifest']->template_version_id, $again['manifest']->pinned_manifest_digest]);
        $this->assertSame(1, DocumentManifest::count());
        $this->assertSame(1, DocumentGenerationIntent::count());

        // A genuinely new generation of the same document freezes under the newer configuration.
        $second = $this->bind(identity: 'gen-2')['manifest'];
        $this->assertSame($t2, $second->template_version_id);
    }

    public function test_an_explicit_choice_is_resolved_and_pinned_and_an_unfit_choice_refuses(): void
    {
        $t1 = $this->template('standard');
        $t2 = $this->template('alternate');
        $manifest = $this->bind(template: $t2)['manifest'];
        $this->assertSame([$t2, 'EXPLICIT'], [$manifest->template_version_id, $manifest->resolved_template['source']]);
        $this->assertSame('version_not_found', $this->refusal(fn () => $this->bind(identity: 'gen-x', template: 9999999)));
        $this->assertNotSame($t1, $manifest->template_version_id);
    }

    // ---- identity ------------------------------------------------------------------------------------------------------------------------------------

    public function test_the_same_identity_with_different_frozen_content_is_a_conflict_never_a_silent_reuse(): void
    {
        $first = $this->bind()['manifest'];
        $before = $this->counts();
        $changed = PurchaseOrderShapedAdapter::projection(['header' => ['po_number' => 'PO-0001', 'supplier_name' => 'Acme Supplies', 'currency_code' => 'PKR', 'order_date' => '2026-10-21', 'total' => '9999.0000']]);

        $this->assertSame('manifest_conflict', $this->refusal(fn () => $this->bind($changed)));
        $this->assertSame('manifest_conflict', $this->refusal(fn () => $this->bind(reference: 'a different snapshot')));
        $this->assertSame($before, $this->counts(), 'the conflicting bind left nothing behind, not even a binding or receipt');
        $this->assertSame($first->input_digest, DocumentManifest::query()->sole()->input_digest);

        // The changed document is its own generation, with its own manifest and intent.
        $second = $this->bind($changed, identity: 'gen-2');
        $this->assertFalse($second['replayed']);
        $this->assertNotSame($first->id, $second['manifest']->id);
        // Another purpose of the same identity is a separate freeze too.
        $this->assertFalse($this->bind(identity: 'gen-1', purpose: DocumentManifest::DRAFT_PREVIEW)['replayed']);
    }

    public function test_a_key_replays_its_own_request_and_refuses_a_different_one(): void
    {
        $first = $this->bind(key: 'same');
        $replay = $this->bind(key: 'same');
        $this->assertTrue($replay['replayed']);
        $this->assertSame([$first['manifest']->id, $first['intent']->id], [$replay['manifest']->id, $replay['intent']->id]);
        $before = $this->counts();

        $this->assertSame('key_conflict', $this->refusal(fn () => $this->bind(identity: 'gen-2', key: 'same')));
        $this->assertSame($before, $this->counts());
        // Fresh keys on the same identity and content never create a second manifest or intent.
        foreach (range(1, 3) as $i) {
            $this->assertTrue($this->bind()['replayed']);
        }
        $this->assertSame([1, 1], [DocumentManifest::count(), DocumentGenerationIntent::count()]);
    }

    public function test_invalid_requests_refuse_before_anything_is_written(): void
    {
        $before = $this->counts();
        $this->assertSame('purpose_invalid', $this->refusal(fn () => $this->bind(purpose: 'FINAL')));
        $this->assertSame('manifest_invalid', $this->refusal(fn () => $this->bind(identity: '   ')));
        $this->assertSame('manifest_invalid', $this->refusal(fn () => $this->bind(identity: str_repeat('x', 121))));
        $this->assertSame('manifest_invalid', $this->refusal(fn () => $this->bind(generation: 0)));
        $this->assertSame('manifest_invalid', $this->refusal(fn () => $this->bind(reference: '  ')));
        $this->assertSame('manifest_invalid', $this->refusal(fn () => $this->bind(key: '')));
        $this->assertSame('audience_unsupported', $this->refusal(fn () => app(BindDocumentManifest::class)->execute(PurchaseOrderShapedAdapter::projection(), 'CUSTOMER', 'po:1', 1, 'ORIGINAL', 'g', 'k', null)));
        $this->assertSame('schema_incompatible', $this->refusal(fn () => $this->bind(new DocumentProjection(self::PO, 2, []))));
        $this->assertSame('adapter_missing', $this->refusal(fn () => $this->bind(new DocumentProjection('nobody.home.type', 1, []))));
        $this->assertNotSame('', $this->refusal(fn () => $this->bind(PurchaseOrderShapedAdapter::projection(['header' => ['po_number' => 'PO-1']]))));
        $this->assertSame($before, $this->counts());
    }

    public function test_a_failure_after_binding_rolls_the_owners_snapshot_back_with_it(): void
    {
        $before = $this->counts();
        try {
            DB::transaction(function (): void {
                DB::table('companies')->where('id', 1)->update(['name' => 'Renamed inside the owner transaction']);
                $this->bind();
                throw new RuntimeException('the owner failed after binding');
            });
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame($before, $this->counts(), 'no binding, manifest, intent or receipt survives');
        $this->assertNotSame('Renamed inside the owner transaction', DB::table('companies')->where('id', 1)->value('name'));
    }

    // ---- access --------------------------------------------------------------------------------------------------------------------------------------

    public function test_denied_or_throwing_access_fails_closed_and_is_logged_without_content(): void
    {
        Log::spy();
        $this->registry = new DocumentTypeRegistry;
        $this->registry->register(new PurchaseOrderShapedAdapter(allow: false));
        $this->registry->register(new CountSheetAdapter(throws: true));
        $this->app->instance(DocumentTypeRegistry::class, $this->registry);

        $this->assertSame('access_denied', $this->refusal(fn () => $this->bind()));
        $sheet = new DocumentProjection('inventory.count-sheet.blind', 1, ['header' => ['sheet_ref' => 'C1', 'location' => 'MAIN'], 'sections' => ['items' => [['bin' => 'A1', 'item_code' => 'X']]]]);
        $this->assertSame('access_denied', $this->refusal(fn () => app(BindDocumentManifest::class)->execute($sheet, 'COUNTER', 'count:1', 1, 'ORIGINAL', 'g', 'k', $this->user->id)));
        $this->assertSame([0, 0, 0, 0], $this->counts());

        Log::shouldHaveReceived('warning')->with('documents.access_refused', \Mockery::on(fn (array $c) => $c['outcome'] === 'denied' && $c['operation'] === 'bind_manifest' && $c['type_key'] === self::PO
            && $c['company_id'] === 1 && $c['actor_id'] === $this->user->id && ! array_key_exists('exception', $c)))->once();
        Log::shouldHaveReceived('warning')->with('documents.access_refused', \Mockery::on(fn (array $c) => $c['outcome'] === 'threw' && $c['type_key'] === 'inventory.count-sheet.blind' && isset($c['exception'])))->once();
        Log::shouldHaveReceived('warning')->with('documents.access_refused', \Mockery::on(function (array $c): bool {
            $flat = json_encode($c);

            return ! str_contains((string) $flat, 'Acme Supplies') && ! str_contains((string) $flat, 'po:1') && ! str_contains((string) $flat, 'count:1');
        }))->twice();
    }

    // ---- intents -------------------------------------------------------------------------------------------------------------------------------------

    public function test_a_retry_is_accepted_only_after_every_earlier_intent_failed_and_is_idempotent(): void
    {
        $manifest = $this->bind()['manifest'];
        $this->assertSame('generation_active', $this->refusal(fn () => app(RequestDocumentGeneration::class)->execute($manifest->id, 'retry-1', $this->key(), $this->user->id)));

        DB::table('document_generation_intents')->where('manifest_id', $manifest->id)->update(['state' => 'FAILED', 'error_code' => 'render_timeout']);
        $key = $this->key();
        $retry = app(RequestDocumentGeneration::class)->execute($manifest->id, 'retry-1', $key, $this->user->id);
        $this->assertFalse($retry['replayed']);
        $this->assertSame(['PENDING', 0, 'retry-1'], [$retry['intent']->state, $retry['intent']->fencing_generation, $retry['intent']->operation_identity]);
        $this->assertSame(2, DocumentGenerationIntent::where('manifest_id', $manifest->id)->count());

        $again = app(RequestDocumentGeneration::class)->execute($manifest->id, 'retry-1', $key, $this->user->id);
        $this->assertTrue($again['replayed']);
        $this->assertSame($retry['intent']->id, $again['intent']->id);
        $this->assertSame('key_conflict', $this->refusal(fn () => app(RequestDocumentGeneration::class)->execute($manifest->id, 'retry-2', $key, $this->user->id)));
        // Another fresh key for the same identity resolves the same intent; a second identity is refused while the first is pending.
        $this->assertSame($retry['intent']->id, app(RequestDocumentGeneration::class)->execute($manifest->id, 'retry-1', $this->key(), $this->user->id)['intent']->id);
        $this->assertSame('generation_active', $this->refusal(fn () => app(RequestDocumentGeneration::class)->execute($manifest->id, 'retry-2', $this->key(), $this->user->id)));
        $this->assertSame('manifest_not_found', $this->refusal(fn () => app(RequestDocumentGeneration::class)->execute(9999999, 'x', $this->key(), $this->user->id)));
        $this->assertSame('request_invalid', $this->refusal(fn () => app(RequestDocumentGeneration::class)->execute($manifest->id, ' ', $this->key(), $this->user->id)));
    }

    public function test_requesting_and_reading_ask_the_owner_every_time(): void
    {
        $manifest = $this->bind()['manifest'];
        $deny = new PurchaseOrderShapedAdapter(allow: false);
        $registry = new DocumentTypeRegistry;
        $registry->register($deny);
        $this->app->instance(DocumentTypeRegistry::class, $registry);

        $this->assertSame('access_denied', $this->refusal(fn () => app(RequestDocumentGeneration::class)->execute($manifest->id, 'retry-1', $this->key(), $this->user->id)));
        $this->assertSame('access_denied', $this->refusal(fn () => app(ReadDocumentGenerationStatus::class)->execute($manifest->id, null, $this->user->id)));
        $this->assertSame(['request_generation', 'read_status'], array_map(fn ($c) => $c->operation, $deny->asked));
        $this->assertSame(1, DocumentGenerationIntent::count());
    }

    public function test_the_status_is_safe_stable_and_never_a_payload(): void
    {
        $manifest = $this->bind(purpose: DocumentManifest::DRAFT_PREVIEW)['manifest'];
        $read = fn (?string $identity = null) => app(ReadDocumentGenerationStatus::class)->execute($manifest->id, $identity, $this->user->id);

        $this->assertEquals(new DocumentGenerationStatus($manifest->id, 'DRAFT_PREVIEW', 'initial', 'pending', null), $read());
        $this->assertSame('missing', $read('no-such-operation')->status);
        DB::table('document_generation_intents')->where('manifest_id', $manifest->id)->update(['state' => 'RUNNING', 'fencing_generation' => 1, 'lease_expiry' => now()->addMinute()]);
        $this->assertSame('running', $read()->status);
        DB::table('document_generation_intents')->where('manifest_id', $manifest->id)->update(['state' => 'FAILED', 'lease_expiry' => null, 'error_code' => 'render_timeout']);
        $failed = $read();
        $this->assertSame(['failed', 'render_timeout'], [$failed->status, $failed->errorCode]);
        $this->assertSame(['manifestId', 'purpose', 'operationIdentity', 'status', 'errorCode'], array_map(fn ($p) => $p->getName(), (new \ReflectionClass($failed))->getProperties()));
        $this->assertSame('manifest_not_found', $this->refusal(fn () => app(ReadDocumentGenerationStatus::class)->execute(9999999)));
    }

    public function test_the_intent_state_machine_and_fence_are_enforced_by_the_database(): void
    {
        $intent = $this->bind()['intent'];
        $id = $intent->id;
        $set = fn (array $values) => fn () => DB::table('document_generation_intents')->where('id', $id)->update($values);

        // Born PENDING at fence 0, unleased; nothing else can be inserted.
        $row = ['company_id' => 1, 'manifest_id' => $intent->manifest_id, 'operation_identity' => 'x', 'request_key' => 'k', 'intent_digest' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()];
        foreach ([['state' => 'RUNNING', 'lease_expiry' => now()->addMinute()], ['fencing_generation' => 1], ['state' => 'FAILED', 'error_code' => 'e'], ['lease_expiry' => now()->addMinute()]] as $bad) {
            $this->assertRefused(fn () => DB::table('document_generation_intents')->insert([...$row, ...$bad]), 'created PENDING, unleased, at fence 0|document_generation_intents_shape');
        }
        // Illegal moves and shape.
        $this->assertRefused($set(['state' => 'VERIFIED']), 'cannot move from PENDING to VERIFIED');
        $this->assertRefused($set(['state' => 'RUNNING', 'lease_expiry' => now()->addMinute()]), 'advances its fence');
        $this->assertRefused($set(['state' => 'RUNNING', 'fencing_generation' => 1]), 'document_generation_intents_shape');
        $this->assertRefused($set(['state' => 'FAILED']), 'document_generation_intents_shape');
        $this->assertRefused($set(['state' => 'SOMETIMES']), 'document_generation_intents_shape|cannot move from PENDING to SOMETIMES');
        $this->assertRefused($set(['request_key' => 'other']), 'keeps its identity');
        $this->assertRefused($set(['intent_digest' => str_repeat('b', 64)]), 'keeps its identity');
        $this->assertRefused($set(['operation_identity' => 'other']), 'keeps its identity');

        // The legal path moves the fence forward and never back.
        DB::table('document_generation_intents')->where('id', $id)->update(['state' => 'RUNNING', 'fencing_generation' => 1, 'lease_expiry' => now()->addMinute()]);
        $this->assertRefused($set(['fencing_generation' => 0]), 'only moves forward');
        DB::table('document_generation_intents')->where('id', $id)->update(['state' => 'VERIFIED', 'lease_expiry' => null]);
        foreach (['PENDING', 'RUNNING', 'FAILED'] as $to) {
            $this->assertRefused($set(['state' => $to, 'error_code' => $to === 'FAILED' ? 'e' : null, 'lease_expiry' => $to === 'RUNNING' ? now()->addMinute() : null, 'fencing_generation' => 2]), 'cannot move from VERIFIED|document_generation_intents_shape');
        }
        $this->assertRefused(fn () => DB::table('document_generation_intents')->where('id', $id)->delete(), 'cannot be deleted');
        $this->assertSame('VERIFIED', DB::table('document_generation_intents')->where('id', $id)->value('state'));
    }

    // ---- manifests in the database ---------------------------------------------------------------------------------------------------------------------

    public function test_manifests_are_immutable_truncate_guarded_and_tenant_safe(): void
    {
        $manifest = $this->bind()['manifest'];
        $this->assertRefused(fn () => DB::table('document_manifests')->update(['stable_generation_identity' => 'edited']), 'append-only');
        $this->assertRefused(fn () => DB::table('document_manifests')->update(['frozen_payload' => '{}']), 'append-only');
        $this->assertRefused(fn () => DB::table('document_manifests')->delete(), 'append-only');
        $this->assertRefused(fn () => DB::statement('TRUNCATE TABLE document_manifests, document_generation_intents'), 'append-only');
        $this->assertRefused(fn () => DB::statement('TRUNCATE TABLE document_generation_intents'), 'append-only');
        $this->assertRefused(fn () => DB::statement('TRUNCATE TABLE document_subject_bindings CASCADE'), 'append-only|cannot truncate');
        $this->assertSame(1, DB::table('document_manifests')->count());

        $row = (array) DB::table('document_manifests')->find($manifest->id);
        unset($row['id']);
        $row = [...$row, 'frozen_payload' => json_encode($manifest->frozen_payload), 'resolved_template' => json_encode($manifest->resolved_template), 'resolved_page_policy' => json_encode($manifest->resolved_page_policy),
            'asset_manifest' => '[]', 'fonts' => '[]', 'locale_formatting' => json_encode($manifest->locale_formatting), 'stable_generation_identity' => 'raw-1'];
        $this->assertRefused(fn () => DB::table('document_manifests')->insert([...$row, 'stable_generation_identity' => $manifest->stable_generation_identity]), 'document_manifests_identity_unique');
        $this->assertRefused(fn () => DB::table('document_manifests')->insert([...$row, 'company_id' => $this->otherCompany]), 'document_manifests_binding_fk');
        $this->assertRefused(fn () => DB::table('document_manifests')->insert([...$row, 'initiator_id' => 9999999]), 'document_manifests_initiator_fk');
        $this->assertRefused(fn () => DB::table('document_manifests')->insert([...$row, 'template_version_id' => 9999999]), 'document_manifests_template_fk');
        foreach ([['purpose' => 'FINAL'], ['stable_generation_identity' => ' '], ['input_digest' => 'NOT-HEX'], ['pinned_manifest_digest' => str_repeat('A', 64)], ['schema_version' => 0], ['source_generation' => 0],
            ['brand_state' => 'PINNED'], ['asset_manifest' => '{}'], ['fonts' => '{}'], ['locale_formatting' => '[]'], ['frozen_payload' => '[]'], ['renderer_contract' => ' '], ['source_snapshot_reference' => ' ']] as $bad) {
            $this->assertRefused(fn () => DB::table('document_manifests')->insert([...$row, ...$bad]), 'document_manifests_shape');
        }
        $this->assertRefused(fn () => DB::table('document_manifests')->insert([...$row, 'frozen_payload' => json_encode(['x' => str_repeat('y', 1048577)])]), 'document_manifests_shape');
    }

    public function test_the_manifest_time_is_the_database_clock_whatever_the_writer_supplied(): void
    {
        $manifest = $this->bind()['manifest'];
        $row = (array) DB::table('document_manifests')->find($manifest->id);
        unset($row['id']);
        foreach (['1999-01-01 00:00:00+00', '2099-01-01 00:00:00+00'] as $i => $client) {
            $id = DB::table('document_manifests')->insertGetId([...$row, 'stable_generation_identity' => "clock-{$i}", 'recorded_at' => $client]);
            $stored = DB::table('document_manifests')->find($id)->recorded_at;
            $distance = (float) DB::selectOne('select abs(extract(epoch from (clock_timestamp() - ?::timestamptz))) as d', [$stored])->d;
            $this->assertLessThan(5.0, $distance, "the supplied {$client} was discarded");
        }
        $intent = (array) DB::table('document_generation_intents')->where('manifest_id', $manifest->id)->first();
        unset($intent['id']);
        $id = DB::table('document_generation_intents')->insertGetId([...$intent, 'operation_identity' => 'clock', 'created_at' => '1999-01-01 00:00:00+00', 'updated_at' => '1999-01-01 00:00:00+00']);
        $this->assertLessThan(5.0, (float) DB::selectOne('select abs(extract(epoch from (clock_timestamp() - ?::timestamptz))) as d', [DB::table('document_generation_intents')->find($id)->created_at])->d);
    }

    // ---- configuration pinned by manifests, and the activation chain ----------------------------------------------------------------------------------

    public function test_a_downgrade_off_a_version_a_manifest_pins_is_refused_by_the_real_usage_port(): void
    {
        $this->assertInstanceOf(ManifestConfigurationUsage::class, app(DocumentConfigurationUsage::class));
        $t1 = $this->template();
        $t2 = $this->template();
        $p1 = $this->page();
        $this->activate($t1, $p1, 0);
        $this->activate($t2, null, 1);
        $pinned = $this->bind()['manifest'];
        $this->assertSame([$t2, $p1], [$pinned->template_version_id, $pinned->page_policy_version_id]);

        $this->assertTrue(app(DocumentConfigurationUsage::class)->isPinned(1, 'template', $t2));
        $this->assertTrue(app(DocumentConfigurationUsage::class)->isPinned(1, 'page_policy', $p1));
        $this->assertFalse(app(DocumentConfigurationUsage::class)->isPinned(1, 'template', $t1));
        $this->assertFalse(app(DocumentConfigurationUsage::class)->isPinned($this->otherCompany, 'template', $t2), 'another company\'s freeze pins nothing here');
        $this->assertSame('kind_unknown', $this->refusal(fn () => app(DocumentConfigurationUsage::class)->isPinned(1, 'brand', 1)));

        // Leaving v2 for v1 is a downgrade off a pinned version: refused, however good the reason.
        $this->assertSame('version_pinned', $this->refusal(fn () => $this->activate($t1, null, 2, 'back out')));
        $this->assertSame(2, DB::table('document_company_defaults')->where('type_key', self::PO)->value('generation'));
        // An upgrade off a pinned version is fine; so is a downgrade off one nothing pins.
        $t3 = $this->template();
        $this->activate($t3, null, 2);
        $this->activate($t2, null, 3, 'back to v2');
    }

    public function test_the_activation_chain_is_enforced_by_the_database(): void
    {
        $t1 = $this->template();
        $t2 = $this->template();
        $this->activate($t1, null, 0);
        $this->activate($t2, null, 1);
        $row = ['company_id' => 1, 'type_key' => self::PO, 'template_version_id' => $t1, 'page_policy_version_id' => null, 'activated_at' => now()];

        // Generation 3 must continue generation 2 (which left $t2 active) and derive its downgrade flag.
        $this->assertRefused(fn () => DB::table('document_default_activations')->insert([...$row, 'generation' => 3, 'previous_template_version_id' => $t1, 'is_downgrade' => true, 'reason' => 'r']), 'must name the versions');
        $this->assertRefused(fn () => DB::table('document_default_activations')->insert([...$row, 'generation' => 3, 'previous_template_version_id' => null, 'is_downgrade' => true, 'reason' => 'r']), 'must name the versions');
        $this->assertRefused(fn () => DB::table('document_default_activations')->insert([...$row, 'generation' => 3, 'previous_template_version_id' => $t2, 'is_downgrade' => false]), 'downgrade flag of an activation is derived');
        $this->assertRefused(fn () => DB::table('document_default_activations')->insert([...$row, 'generation' => 5, 'previous_template_version_id' => $t2, 'is_downgrade' => true, 'reason' => 'r']), 'continues the previous generation');
        // The first activation of a default has no previous version.
        $this->assertRefused(fn () => DB::table('document_default_activations')->insert([...$row, 'type_key' => '*', 'template_version_id' => null, 'page_policy_version_id' => $this->page(), 'generation' => 1, 'previous_template_version_id' => $t1]), 'has no previous version');
        // An upgrade asserted as a downgrade is refused too; the honest one is accepted (it still needs its default to follow, so settle only the chain trigger here).
        $this->assertRefused(fn () => DB::table('document_default_activations')->insert([...$row, 'generation' => 3, 'template_version_id' => $t2, 'previous_template_version_id' => $t2, 'is_downgrade' => true, 'reason' => 'r']), 'downgrade flag of an activation is derived|must equal its activation event');
        $this->assertSame(3, DB::table('document_default_activations')->count() + 1, 'only the two real activations exist');
    }

    // ---- no way in, and rollback --------------------------------------------------------------------------------------------------------------------

    public function test_there_is_no_way_in_and_nothing_is_registered(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->uri());
        $this->assertSame([], $uris->filter(fn ($u) => preg_match('#(^|/)(documents?|document-manifests?|document-generations?)(/|$)#', $u) === 1)->values()->all());
        $this->assertSame([], array_values(array_filter(app(PermissionRegistry::class)->all(), fn ($p) => str_starts_with($p, 'documents.') || str_contains($p, 'manifest'))));
        $this->assertDirectoryDoesNotExist(base_path('app/Modules/Documents/Http'));
        $this->assertFileDoesNotExist(base_path('app/Modules/Documents/routes.php'));
    }

    public function test_rollback_refuses_while_manifests_exist_and_works_when_empty(): void
    {
        $migration = require base_path('database/migrations/2026_10_28_100000_create_document_manifests_and_intents.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('document_manifests'));
        $this->assertFalse(Schema::hasTable('document_generation_intents'));
        $this->assertSame(0, (int) DB::selectOne("select count(*) c from pg_trigger where tgname = 'document_default_activation_chain_guard'")->c);
        $this->assertTrue(Schema::hasTable('document_subject_bindings'), 'DF0/DF1a objects are untouched');
        $migration->up();
        $this->assertTrue(Schema::hasTable('document_manifests'));

        $this->bind();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        try {
            $migration->down();
            $this->fail('Rolling back with frozen evidence present must refuse.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Plan a backout first', $e->getMessage());
        }
        $this->assertSame(1, DB::table('document_manifests')->count());
    }
}
