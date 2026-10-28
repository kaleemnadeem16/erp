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

    private function bind(string $identity = 'gen-1', string $key = 'k', int $generation = 1): array
    {
        return app(BindDocumentManifest::class)->execute(PurchaseOrderShapedAdapter::projection(), 'SUPPLIER', 'po:1', $generation, 'ORIGINAL', $identity, $key, $this->user->id);
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
                $this->bind('gen-2', 'k2', 2);
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

    /**
     * Two genuinely separate OS processes (pcntl_fork, the repository's existing concurrency technique) each run the COMPLETE Action with a different fresh key on
     * the same document identity, released together. Each has its own connection; the committed fixtures are wiped by migrate:fresh afterwards.
     *
     * @return array{child: array<string, mixed>, parent: array<string, mixed>}
     */
    private function race(\Closure $childWork, \Closure $parentWork): array
    {
        $resultPath = sys_get_temp_dir().'/doc_manifest_race_'.getmypid().'.json';
        $startPath = sys_get_temp_dir().'/doc_manifest_race_'.getmypid().'.start';
        @unlink($resultPath);
        @unlink($startPath);
        $outcome = static function (\Closure $work): array {
            try {
                $result = $work();

                return ['ok' => true, 'manifest' => $result['manifest']->id, 'replayed' => $result['replayed']];
            } catch (DocumentException $e) {
                return ['ok' => false, 'reason' => $e->reasonCode];
            } catch (\Throwable $e) {
                return ['ok' => false, 'reason' => 'ERROR: '.$e->getMessage()];
            }
        };
        try {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid, 'unable to fork');
            if ($pid === 0) {
                config(['database.connections.doc_race_child' => config('database.connections.testing'), 'database.default' => 'doc_race_child']);
                for ($waited = 0; ! file_exists($startPath) && $waited < 3_000_000; $waited += 1000) {
                    usleep(1000);
                }
                file_put_contents($resultPath, json_encode($outcome($childWork)));
                posix_kill(getmypid(), SIGKILL);
            }
            $default = config('database.default');
            config(['database.connections.doc_race_parent' => config('database.connections.testing'), 'database.default' => 'doc_race_parent']);
            file_put_contents($startPath, '1');
            try {
                $parent = $outcome($parentWork);
            } finally {
                config(['database.default' => $default]);
                DB::purge('doc_race_parent');
            }
            pcntl_waitpid($pid, $status);
            $child = json_decode((string) file_get_contents($resultPath), true);
            $this->assertIsArray($child, 'the forked child did not report an outcome');

            return ['child' => $child, 'parent' => $parent];
        } finally {
            @unlink($resultPath);
            @unlink($startPath);
        }
    }

    public function test_two_processes_freezing_the_same_content_under_different_fresh_keys_produce_one_manifest(): void
    {
        $bind = fn (string $key) => fn () => app(BindDocumentManifest::class)->execute(PurchaseOrderShapedAdapter::projection(), 'SUPPLIER', 'po:1', 1, 'ORIGINAL', 'gen-1', $key, $this->user->id);
        $result = $this->race($bind('child-key'), $bind('parent-key'));

        $this->assertTrue($result['child']['ok'] && $result['parent']['ok'], json_encode($result));
        $this->assertSame($result['child']['manifest'], $result['parent']['manifest'], 'both resolve the one manifest');
        $this->assertSame(1, count(array_filter([$result['child']['replayed'], $result['parent']['replayed']])), 'exactly one of them created it');
        $this->assertSame([1, 1, 1], [DB::table('document_manifests')->count(), DB::table('document_generation_intents')->count(), DB::table('document_subject_bindings')->count()]);
    }

    public function test_two_processes_freezing_conflicting_content_under_different_fresh_keys_produce_exactly_one_winner(): void
    {
        $changed = PurchaseOrderShapedAdapter::projection(['header' => ['po_number' => 'PO-0001', 'supplier_name' => 'Acme Supplies', 'currency_code' => 'PKR', 'order_date' => '2026-10-21', 'total' => '9999.0000']]);
        $a = fn () => app(BindDocumentManifest::class)->execute(PurchaseOrderShapedAdapter::projection(), 'SUPPLIER', 'po:1', 1, 'ORIGINAL', 'gen-1', 'child-key', $this->user->id);
        $b = fn () => app(BindDocumentManifest::class)->execute($changed, 'SUPPLIER', 'po:1', 1, 'ORIGINAL', 'gen-1', 'parent-key', $this->user->id);
        $result = $this->race($a, $b);

        $outcomes = [$result['child'], $result['parent']];
        $this->assertCount(1, array_filter($outcomes, fn ($o) => $o['ok']), 'exactly one freeze wins: '.json_encode($result));
        $loser = array_values(array_filter($outcomes, fn ($o) => ! $o['ok']))[0];
        $this->assertSame('manifest_conflict', $loser['reason']);
        $this->assertSame([1, 1], [DB::table('document_manifests')->count(), DB::table('document_generation_intents')->count()]);
        $this->assertSame(1, DB::table('document_operation_receipts')->where('operation', 'bind_manifest')->count(), 'the loser left no receipt');
    }
}
