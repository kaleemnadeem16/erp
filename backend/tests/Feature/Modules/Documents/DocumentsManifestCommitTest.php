<?php

namespace Tests\Feature\Modules\Documents;

use App\Models\User;
use App\Modules\Documents\Actions\ActivateDocumentConfiguration;
use App\Modules\Documents\Actions\BindDocumentManifest;
use App\Modules\Documents\Actions\VersionDocumentTemplate;
use App\Modules\Documents\Models\DocumentManifest;
use App\Modules\Documents\Support\DocumentException;
use App\Modules\Documents\Support\DocumentLayouts;
use App\Modules\Documents\Support\DocumentTypeRegistry;
use App\Modules\Documents\Support\TemplateConfiguration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\Documents\PurchaseOrderShapedAdapter;
use Tests\TestCase;

/**
 * DF2 — the real COMMIT and two real sessions. A test transaction never commits, so serialisation on the document identity and on the Company default is proven
 * here with committed fixtures and a second PostgreSQL connection that probes the same advisory locks the Actions take.
 */
class DocumentsManifestCommitTest extends TestCase
{
    use DatabaseMigrations;

    private const PO = PurchaseOrderShapedAdapter::KEY;

    private User $user;

    public function runDatabaseMigrations(): void
    {
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();
        $this->beforeApplicationDestroyed(function (): void {
            // A schema downgrade deliberately refuses frozen document evidence; this isolated test database is disposable, so wipe and rebuild it.
            DB::purge('doc_b');
            $this->artisan('migrate:fresh', $this->migrateFreshUsing());
            RefreshDatabaseState::$migrated = false;
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.doc_b' => config('database.connections.testing')]);
        $this->user = User::factory()->create();
        $registry = new DocumentTypeRegistry;
        $registry->register(new PurchaseOrderShapedAdapter(supportsLetter: true));
        $this->app->instance(DocumentTypeRegistry::class, $registry);
    }

    private function bind(string $identity = 'gen-1', string $key = 'k'): array
    {
        return app(BindDocumentManifest::class)->execute(PurchaseOrderShapedAdapter::projection(), 'SUPPLIER', 'po:1', 1, 'ORIGINAL', $identity, $key, $this->user->id);
    }

    private function tryLock(string $name, bool $shared = false): bool
    {
        $fn = $shared ? 'pg_try_advisory_xact_lock_shared' : 'pg_try_advisory_xact_lock';

        return (bool) DB::connection('doc_b')->selectOne("select {$fn}(hashtext(?)) as got", [$name])->got;
    }

    public function test_a_freeze_commits_for_real_and_a_failure_after_binding_leaves_nothing(): void
    {
        $first = $this->bind();
        $this->assertFalse($first['replayed']);
        $this->assertSame([1, 1, 1], [DB::table('document_manifests')->count(), DB::table('document_generation_intents')->count(), DB::table('document_subject_bindings')->count()]);

        try {
            DB::transaction(function (): void {
                $this->bind('gen-2', 'k2');
                throw new RuntimeException('the owner failed after binding');
            });
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame([1, 1, 1, 2], [DB::table('document_manifests')->count(), DB::table('document_generation_intents')->count(), DB::table('document_subject_bindings')->count(),
            DB::table('document_operation_receipts')->count()], 'the rolled-back freeze left no manifest, intent, binding or receipt');
    }

    public function test_concurrent_fresh_keys_on_one_identity_serialise_and_produce_exactly_one_manifest(): void
    {
        $lock = 'documents:manifest:1:'.self::PO.':SUPPLIER:po:1:ORIGINAL:gen-1';
        DB::beginTransaction();
        try {
            $this->bind('gen-1', 'session-a');
            // While the first freeze is open, a competing session cannot take the identity lock: it would have to wait, and then it finds the winner's manifest.
            DB::connection('doc_b')->statement('BEGIN');
            $this->assertFalse($this->tryLock($lock), 'the identity lock is held by the open freeze');
            DB::connection('doc_b')->statement('ROLLBACK');
        } finally {
            DB::commit();
        }
        DB::connection('doc_b')->statement('BEGIN');
        $this->assertTrue($this->tryLock($lock), 'free again once the freeze committed');
        DB::connection('doc_b')->statement('ROLLBACK');

        $second = $this->bind('gen-1', 'session-b');
        $third = $this->bind('gen-1', 'session-c');
        $this->assertTrue($second['replayed'] && $third['replayed']);
        $this->assertSame(1, DB::table('document_manifests')->count());
        $this->assertSame(1, DB::table('document_generation_intents')->count());
    }

    public function test_a_freeze_and_a_default_activation_exclude_each_other(): void
    {
        $t1 = app(VersionDocumentTemplate::class)->execute(self::PO, 'standard', new TemplateConfiguration(DocumentLayouts::STANDARD, ['lines', 'terms'], ['lines']), $this->user->id)->id;
        app(ActivateDocumentConfiguration::class)->execute(self::PO, $t1, null, 0, $this->user->id);

        // A freeze in progress holds the default scopes shared: an activation (exclusive) would wait for it, two freezes would not wait for each other.
        DB::beginTransaction();
        try {
            $this->bind();
            DB::connection('doc_b')->statement('BEGIN');
            $this->assertFalse($this->tryLock('documents:default:1:'.self::PO), 'an activation of this type must wait for the open freeze');
            $this->assertFalse($this->tryLock('documents:default:1:*'), 'so must a Company-wide activation');
            $this->assertTrue($this->tryLock('documents:default:1:'.self::PO, shared: true), 'a second freeze is not blocked by the first');
            DB::connection('doc_b')->statement('ROLLBACK');
        } finally {
            DB::rollBack();
        }

        // An activation in progress is waited for by a freeze (the freeze times out here rather than pinning the version being replaced).
        DB::connection('doc_b')->statement('BEGIN');
        $this->assertTrue($this->tryLock('documents:default:1:'.self::PO));
        DB::beginTransaction();
        try {
            DB::statement("SET LOCAL lock_timeout = '300ms'");
            $this->bind('gen-2', 'blocked');
            $this->fail('A freeze must wait for an activation in progress.');
        } catch (QueryException $e) {
            $this->assertSame('55P03', $e->errorInfo[0]);
        } finally {
            DB::rollBack();
            DB::connection('doc_b')->statement('ROLLBACK');
        }
        $this->assertSame(0, DocumentManifest::count());
        $this->bind('gen-2', 'free-again');
        $this->assertSame(1, DocumentManifest::count());
    }

    public function test_concurrent_activations_of_one_default_cannot_both_win(): void
    {
        $t1 = app(VersionDocumentTemplate::class)->execute(self::PO, 'standard', new TemplateConfiguration(DocumentLayouts::STANDARD, ['lines', 'terms'], ['lines']), $this->user->id)->id;
        $t2 = app(VersionDocumentTemplate::class)->execute(self::PO, 'standard', new TemplateConfiguration(DocumentLayouts::STANDARD, ['lines', 'terms'], ['lines']), $this->user->id)->id;

        // Two callers both read generation 0; the advisory lock plus the unique (type, generation) activation make exactly one of them win.
        app(ActivateDocumentConfiguration::class)->execute(self::PO, $t1, null, 0, $this->user->id);
        try {
            app(ActivateDocumentConfiguration::class)->execute(self::PO, $t2, null, 0, $this->user->id);
            $this->fail('The stale activation must be refused.');
        } catch (DocumentException $e) {
            $this->assertSame('generation_conflict', $e->reasonCode);
        }
        // A raw competing writer that skipped the Action is stopped by the chain guard and the unique generation.
        DB::connection('doc_b')->statement('BEGIN');
        try {
            DB::connection('doc_b')->table('document_default_activations')->insert(['company_id' => 1, 'type_key' => self::PO, 'generation' => 1, 'template_version_id' => $t2, 'activated_at' => now()]);
            DB::connection('doc_b')->statement('COMMIT');
            $this->fail('A second generation 1 must be refused.');
        } catch (QueryException $e) {
            $this->assertContains($e->errorInfo[0], ['23505', '23514']);
            DB::connection('doc_b')->statement('ROLLBACK');
        }
        $this->assertSame(1, DB::table('document_default_activations')->where('type_key', self::PO)->count());
    }
}
