<?php

namespace Tests\Feature\Modules\Documents;

use App\Models\User;
use App\Modules\Documents\Actions\BindDocumentSubject;
use App\Modules\Documents\Models\DocumentOperationReceipt;
use App\Modules\Documents\Models\DocumentSubjectBinding;
use App\Modules\Documents\Support\DocumentException;
use App\Modules\Documents\Support\DocumentProjection;
use App\Modules\Documents\Support\DocumentTypeRegistry;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\Documents\CountSheetAdapter;
use Tests\Support\Documents\PurchaseOrderShapedAdapter;
use Tests\TestCase;

/**
 * DF0 — the Documents contract and common model over the real database: a missing adapter refuses, the database refuses cross-company and invalid raw
 * writes, history is immutable, a document identity is never duplicated, and a PO-shaped fixture passes with zero Purchasing writes (ADR 0137).
 */
class DocumentsFoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private DocumentTypeRegistry $registry;

    private int $otherCompany;

    private int $otherUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->registry = new DocumentTypeRegistry;
        $this->app->instance(DocumentTypeRegistry::class, $this->registry);
        $this->otherCompany = DB::table('companies')->insertGetId(['name' => 'Other Co', 'created_at' => now(), 'updated_at' => now()]);
        $this->otherUser = DB::table('users')->insertGetId(['company_id' => $this->otherCompany, 'name' => 'O', 'email' => 'o@other.test', 'password' => 'x', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function bind(?DocumentProjection $p = null, string $key = 'k1', string $identity = 'po:1', int $generation = 1, string $audience = 'SUPPLIER'): array
    {
        return app(BindDocumentSubject::class)->execute($p ?? PurchaseOrderShapedAdapter::projection(), $audience, $identity, $generation, $key, $this->user->id);
    }

    private function assertDbRefuses(Closure $write, string $contains): void
    {
        try {
            DB::transaction($write);
        } catch (QueryException $e) {
            $this->assertStringContainsString($contains, $e->getMessage());

            return;
        }
        $this->fail("The database accepted a write that should have been refused ({$contains}).");
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

    public function test_a_missing_adapter_refuses_and_writes_nothing(): void
    {
        $this->assertSame('adapter_missing', $this->refusal(fn () => $this->bind()));
        $this->assertSame(0, DocumentSubjectBinding::count());
        $this->assertSame(0, DocumentOperationReceipt::count());
    }

    public function test_incompatible_version_unknown_audience_and_duplicate_registration_refuse(): void
    {
        $this->registry->register(new PurchaseOrderShapedAdapter);
        $this->assertSame('schema_incompatible', $this->refusal(fn () => $this->bind(new DocumentProjection(PurchaseOrderShapedAdapter::KEY, 2, []))));
        $this->assertSame('audience_unsupported', $this->refusal(fn () => $this->bind(audience: 'CUSTOMER')));
        $this->assertSame('type_duplicate', $this->refusal(fn () => $this->registry->register(new PurchaseOrderShapedAdapter)));
    }

    public function test_denied_or_throwing_access_fails_closed(): void
    {
        $this->registry->register(new PurchaseOrderShapedAdapter(allow: false));
        $this->assertSame('access_denied', $this->refusal(fn () => $this->bind()));

        $this->registry->register(new CountSheetAdapter(throws: true));
        $sheet = new DocumentProjection('inventory.count-sheet.blind', 1, ['header' => ['sheet_ref' => 'C1', 'location' => 'MAIN'], 'sections' => ['items' => [['bin' => 'A1', 'item_code' => 'X']]]]);
        $this->assertSame('access_denied', $this->refusal(fn () => $this->bind($sheet, audience: 'COUNTER')));
        $this->assertSame(0, DocumentSubjectBinding::count());
    }

    public function test_po_shaped_fixture_binds_without_touching_purchasing(): void
    {
        $adapter = new PurchaseOrderShapedAdapter;
        $this->registry->register($adapter);
        $purchasingTables = collect(DB::select("select table_name from information_schema.tables where table_schema = 'public' and table_name like 'purchase\\_%'"))->pluck('table_name');
        $this->assertNotEmpty($purchasingTables);
        $before = $purchasingTables->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()]);

        $result = $this->bind();

        $this->assertFalse($result['replayed']);
        $this->assertSame('purchasing.purchase-order.supplier', $result['binding']->type_key);
        $this->assertSame($this->user->id, $adapter->asked[0]->actorId);
        $this->assertSame('bind', $adapter->asked[0]->operation);
        $this->assertEquals($before, $purchasingTables->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()]));
        $this->assertStringNotContainsString('Modules\\Purchasing', (string) file_get_contents(base_path('tests/Support/Documents/PurchaseOrderShapedAdapter.php')));
    }

    public function test_projection_outside_the_adapter_schema_is_refused(): void
    {
        $this->registry->register(new PurchaseOrderShapedAdapter);
        $good = PurchaseOrderShapedAdapter::projection()->payload;

        $floatTotal = $good;
        $floatTotal['header']['total'] = 1250.5;
        $extra = $good;
        $extra['header']['internal_cost'] = '1.0000';
        $noLines = $good;
        $noLines['sections']['lines'] = [];
        $badDate = $good;
        $badDate['header']['order_date'] = '2026-02-30';

        foreach ([$floatTotal, $extra, $noLines, $badDate] as $payload) {
            $this->assertSame('projection_invalid', $this->refusal(fn () => $this->bind(new DocumentProjection(PurchaseOrderShapedAdapter::KEY, 1, $payload))));
        }
        $this->assertSame(0, DocumentSubjectBinding::count());
    }

    public function test_two_unlike_line_shapes_coexist_without_a_universal_line_schema(): void
    {
        $this->registry->register(new PurchaseOrderShapedAdapter);
        $this->registry->register(new CountSheetAdapter);
        $po = PurchaseOrderShapedAdapter::projection()->payload;
        $this->assertArrayNotHasKey('bin', $po['sections']['lines'][0]);
        $this->assertSame('projection_invalid', $this->refusal(fn () => $this->bind(new DocumentProjection('inventory.count-sheet.blind', 1, $po), audience: 'COUNTER')));
    }

    public function test_same_key_replays_and_fresh_key_resolves_the_one_identity(): void
    {
        $this->registry->register(new PurchaseOrderShapedAdapter);
        $first = $this->bind();
        $replay = $this->bind();
        $fresh = $this->bind(key: 'k2');
        $nextGeneration = $this->bind(key: 'k3', generation: 2);

        $this->assertTrue($replay['replayed']);
        $this->assertSame($first['binding']->id, $replay['binding']->id);
        $this->assertSame($first['binding']->id, $fresh['binding']->id);
        $this->assertNotSame($first['binding']->id, $nextGeneration['binding']->id);
        $this->assertSame(2, DocumentSubjectBinding::where('owner_binding_identity', 'po:1')->count());
        $this->assertSame(3, DocumentOperationReceipt::count());
    }

    public function test_a_changed_intent_under_a_used_key_conflicts(): void
    {
        $this->registry->register(new PurchaseOrderShapedAdapter);
        $this->bind();
        $changed = PurchaseOrderShapedAdapter::projection();
        $changed = new DocumentProjection($changed->typeKey, 1, [...$changed->payload, 'header' => [...$changed->payload['header'], 'total' => '9.0000']]);

        $this->assertSame('key_conflict', $this->refusal(fn () => $this->bind($changed)));
        $this->assertSame(1, DocumentOperationReceipt::count());
    }

    public function test_the_database_refuses_cross_company_and_invalid_raw_writes(): void
    {
        $this->registry->register(new PurchaseOrderShapedAdapter);
        $mine = $this->bind()['binding'];
        $row = ['type_key' => 'a.b', 'schema_version' => 1, 'audience' => 'X', 'owner_binding_identity' => 'i', 'source_generation' => 1, 'bound_at' => now()];

        $this->assertDbRefuses(fn () => DB::table('document_subject_bindings')->insert([...$row, 'company_id' => $this->otherCompany, 'bound_by' => $this->user->id]), 'document_subject_bindings_bound_by_fk');
        $this->assertDbRefuses(fn () => DB::table('document_operation_receipts')->insert(['company_id' => $this->otherCompany, 'operation' => 'bind', 'operation_key' => 'x', 'intent_digest' => str_repeat('a', 64),
            'binding_id' => $mine->id, 'recorded_at' => now()]), 'document_operation_receipts_binding_fk');
        $this->assertDbRefuses(fn () => DB::table('document_operation_receipts')->insert(['company_id' => 1, 'operation' => 'bind', 'operation_key' => 'x', 'intent_digest' => str_repeat('a', 64),
            'binding_id' => $mine->id, 'actor_id' => $this->otherUser, 'recorded_at' => now()]), 'document_operation_receipts_actor_fk');

        foreach ([['type_key' => 'NotDotted'], ['schema_version' => 0], ['source_generation' => 0], ['audience' => ' '], ['owner_binding_identity' => '']] as $bad) {
            $this->assertDbRefuses(fn () => DB::table('document_subject_bindings')->insert([...$row, 'company_id' => 1, ...$bad]), 'document_subject_bindings_shape');
        }
        $this->assertDbRefuses(fn () => DB::table('document_operation_receipts')->insert(['company_id' => 1, 'operation' => 'bind', 'operation_key' => 'x', 'intent_digest' => 'nothex',
            'binding_id' => $mine->id, 'recorded_at' => now()]), 'document_operation_receipts_shape');
    }

    public function test_the_database_refuses_a_duplicate_identity_and_a_duplicate_key(): void
    {
        $this->registry->register(new PurchaseOrderShapedAdapter);
        $mine = $this->bind()['binding'];

        $this->assertDbRefuses(fn () => DB::table('document_subject_bindings')->insert(['company_id' => 1, 'type_key' => $mine->type_key, 'schema_version' => 1, 'audience' => 'SUPPLIER',
            'owner_binding_identity' => 'po:1', 'source_generation' => 1, 'bound_at' => now()]), 'document_subject_bindings_identity_unique');
        $this->assertDbRefuses(fn () => DB::table('document_operation_receipts')->insert(['company_id' => 1, 'operation' => 'bind', 'operation_key' => 'k1', 'intent_digest' => str_repeat('b', 64),
            'binding_id' => $mine->id, 'recorded_at' => now()]), 'document_operation_receipts_key_unique');
    }

    public function test_bindings_and_receipts_are_immutable_history(): void
    {
        $this->registry->register(new PurchaseOrderShapedAdapter);
        $this->bind();

        $this->assertDbRefuses(fn () => DB::table('document_subject_bindings')->update(['audience' => 'OTHER']), 'append-only');
        $this->assertDbRefuses(fn () => DB::table('document_subject_bindings')->delete(), 'append-only');
        $this->assertDbRefuses(fn () => DB::table('document_operation_receipts')->update(['operation' => 'x']), 'append-only');
        $this->assertDbRefuses(fn () => DB::table('document_operation_receipts')->delete(), 'append-only');
    }

    public function test_the_public_entry_is_disabled(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->uri());
        $this->assertSame([], $uris->filter(fn ($u) => str_starts_with($u, 'api/v1/documents'))->values()->all());
    }
}
