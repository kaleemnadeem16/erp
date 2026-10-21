<?php

namespace Tests\Feature\Modules\Sales;

use App\Exceptions\DomainInvariantException;
use App\Models\Currency;
use App\Models\User;
use App\Modules\Documents\Actions\BindDocumentSubject;
use App\Modules\Documents\Models\DocumentSubjectBinding;
use App\Modules\Documents\Support\DocumentProjection;
use App\Modules\Documents\Support\DocumentTypeRegistry;
use App\Modules\Sales\Actions\BindQuotationDocument;
use App\Modules\Sales\Models\QuotationCustomerDocumentSnapshot;
use App\Modules\Sales\Models\QuotationDocumentBinding;
use App\Modules\Sales\Models\QuotationFamily;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Authority\DutyStatus;
use App\Modules\Sales\Support\Authority\SalesDuty;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\Documents\QuotationCustomerDocumentAdapter;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Support\PermissionRegistry;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * DOC0-Sales skeleton — the Sales-owned side of a quotation document over the real database: the adapter is registered with Sales' allowlist and access,
 * binding is idempotent through Documents and creates no second archive identity, the database refuses cross-company and mismatched writes, history is
 * immutable, and the staged duty refuses everyone. Nothing here freezes a real quotation, issues, numbers or renders.
 */
class QuotationDocumentBindingTest extends TestCase
{
    use RefreshDatabase;

    private const DUTY = PermissionVocabulary::DOCUMENT_GENERATE;

    private User $generator;

    private Currency $pkr;

    private QuotationRevision $revision;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        Permission::findOrCreate(self::DUTY, 'web');
        $this->generator = User::factory()->create();
        $this->generator->givePermissionTo(self::DUTY);
        $this->pkr = Currency::firstOrCreate(['code' => 'PKR'], ['name' => 'Rupee', 'symbol' => 'Rs', 'decimal_places' => 2]);
        $family = QuotationFamily::create(['created_by' => $this->generator->id])->refresh();
        $this->revision = QuotationRevision::create(['family_id' => $family->id, 'currency_id' => $this->pkr->id, 'created_by' => $this->generator->id])->refresh();
        $this->enableDuty();
    }

    /** The shipped manifest with the staged duty switched on, to test what the shipped manifest keeps disabled. */
    private function enableDuty(): void
    {
        $duties = array_map(fn (SalesDuty $duty): SalesDuty => $duty->name === self::DUTY
            ? new SalesDuty($duty->name, $duty->class, DutyStatus::Active, $duty->operation, $duty->projection, $duty->deliveryGate) : $duty, SalesDutyManifest::shipped()->all());
        $this->app->instance(SalesDutyManifest::class, new SalesDutyManifest($duties));
    }

    /** @param array<string, mixed> $override */
    private function projection(array $override = []): array
    {
        return [
            'header' => ['quotation_number' => 'Q-00001', 'revision_ordinal' => 1, 'issued_on' => '2026-10-21', 'currency_code' => 'PKR', 'recipient_name' => 'Prospect Ltd', 'net_total' => '27.3300'],
            'sections' => ['lines' => [
                ['description' => 'Prime', 'membership' => 'BASE', 'quantity' => '3.0000', 'unit_price' => '0.3350', 'amount' => '1.0100'],
                ['description' => 'Topcoat', 'membership' => 'BASE', 'quantity' => '2.5000', 'uom' => 'sqm', 'unit_price' => '10.1250', 'amount' => '25.3100'],
            ], 'terms' => ['payment_terms' => 'Net 30']],
            ...$override,
        ];
    }

    private function snapshot(?array $projection = null, ?QuotationRevision $revision = null, bool $fresh = false): QuotationCustomerDocumentSnapshot
    {
        if ($fresh) {
            $family = QuotationFamily::create(['created_by' => $this->generator->id])->refresh();
            $revision = QuotationRevision::create(['family_id' => $family->id, 'currency_id' => $this->pkr->id, 'created_by' => $this->generator->id])->refresh();
        }
        $revision ??= $this->revision;
        $projection ??= $this->projection();

        return QuotationCustomerDocumentSnapshot::create([
            'family_id' => $revision->family_id, 'revision_id' => $revision->id, 'schema_version' => 1, 'projection' => $projection,
            'projection_digest' => (new DocumentProjection(QuotationCustomerDocumentAdapter::KEY, 1, $projection))->digest(),
        ])->refresh();
    }

    private function bind(QuotationCustomerDocumentSnapshot $snapshot, string $key = 'k1', ?User $by = null): array
    {
        return app(BindQuotationDocument::class)($by ?? $this->generator, $snapshot->id, $key);
    }

    private function assertDbRefuses(Closure $write, string $contains): void
    {
        try {
            DB::transaction($write);
        } catch (QueryException $e) {
            $this->assertStringContainsString($contains, $e->getMessage());

            return;
        }
        $this->fail('Expected the database to refuse.');
    }

    public function test_the_quotation_type_is_registered_from_the_sales_provider_with_its_own_allowlist(): void
    {
        $registry = app(DocumentTypeRegistry::class);

        $this->assertTrue($registry->has('sales.quotation.customer'));
        $adapter = $registry->get('sales.quotation.customer', 1);
        $this->assertSame(['CUSTOMER'], $adapter->audiences());
        $this->assertSame(QuotationCustomerDocumentAdapter::class, $adapter::class);
        // Registration is not permission, and nothing here is a route.
        $this->app->instance(SalesDutyManifest::class, SalesDutyManifest::shipped());
        $this->assertNotContains(self::DUTY, app(PermissionRegistry::class)->all());
        $this->assertSame([], array_filter(array_map(fn ($route) => $route->uri(), Route::getRoutes()->getRoutes()), fn (string $uri) => str_contains($uri, 'document') && str_contains($uri, 'quotation')));
    }

    public function test_a_missing_adapter_refuses_and_writes_nothing(): void
    {
        $snapshot = $this->snapshot();
        $this->app->instance(DocumentTypeRegistry::class, new DocumentTypeRegistry);

        $this->assertThrows(fn () => $this->bind($snapshot), DomainInvariantException::class, 'No adapter is registered');
        $this->assertSame(0, DocumentSubjectBinding::query()->count());
        $this->assertSame(0, QuotationDocumentBinding::query()->count());
    }

    public function test_the_duty_is_staged_so_the_action_and_the_adapter_refuse_everyone(): void
    {
        $this->app->instance(SalesDutyManifest::class, SalesDutyManifest::shipped());
        $snapshot = $this->snapshot();
        $this->assertTrue($this->generator->fresh()->can(self::DUTY), 'The raw permission row is a grant in the store…');

        $this->assertThrows(fn () => $this->bind($snapshot), AuthorizationException::class);
        // …and the owner's access callback, reached without the Action, says no as well.
        $this->assertThrows(fn () => app(BindDocumentSubject::class)->execute(new DocumentProjection(QuotationCustomerDocumentAdapter::KEY, 1, $snapshot->projection), 'CUSTOMER', 'quotation-snapshot:'.$snapshot->id, 1, 'k', $this->generator->id), DomainInvariantException::class, 'did not authorise');
        $this->assertSame(0, DocumentSubjectBinding::query()->count());
    }

    public function test_only_a_holder_of_the_duty_may_bind_and_a_missing_actor_is_denied(): void
    {
        $snapshot = $this->snapshot();
        $stranger = User::factory()->create();

        $this->assertThrows(fn () => $this->bind($snapshot, by: $stranger), AuthorizationException::class);
        $this->assertThrows(fn () => app(BindDocumentSubject::class)->execute(new DocumentProjection(QuotationCustomerDocumentAdapter::KEY, 1, $snapshot->projection), 'CUSTOMER', 'quotation-snapshot:'.$snapshot->id, 1, 'k', null), DomainInvariantException::class, 'did not authorise');
        $this->assertSame(0, QuotationDocumentBinding::query()->count());
    }

    public function test_binding_is_idempotent_and_creates_no_second_archive_identity(): void
    {
        $snapshot = $this->snapshot();

        $first = $this->bind($snapshot, 'once');
        $replay = $this->bind($snapshot, 'once');
        $fresh = $this->bind($snapshot, 'another-key');

        $this->assertFalse($first['replayed']);
        $this->assertTrue($replay['replayed']);
        $this->assertSame($first['binding']->document_binding_id, $fresh['binding']->document_binding_id, 'A fresh key for the same identity resolves the original Documents binding.');
        $this->assertSame($first['binding']->id, $fresh['binding']->id);
        $this->assertSame(1, DocumentSubjectBinding::query()->count());
        $this->assertSame(1, QuotationDocumentBinding::query()->count());
        $binding = QuotationDocumentBinding::query()->firstOrFail();
        $this->assertSame(['sales.quotation.customer', 'CUSTOMER', 'quotation-snapshot:'.$snapshot->id], [$binding->documentBinding->type_key, $binding->documentBinding->audience, $binding->documentBinding->owner_binding_identity]);
        $this->assertSame($snapshot->id, $binding->snapshot->id);
    }

    public function test_a_projection_outside_the_sales_allowlist_is_refused(): void
    {
        foreach ([
            'cost in a line' => $this->projection(['sections' => ['lines' => [['description' => 'x', 'membership' => 'BASE', 'quantity' => '1.0000', 'unit_price' => '1.0000', 'amount' => '1.0000', 'unit_cost' => '0.5000']]]]),
            'margin in the header' => $this->projection(['header' => $this->projection()['header'] + ['margin' => '10.0000']]),
            'unknown section' => $this->projection(['sections' => ['lines' => [], 'internal_notes' => ['x' => 'y']]]),
            'float money' => $this->projection(['header' => ['net_total' => 27.33] + $this->projection()['header']]),
        ] as $why => $projection) {
            $snapshot = $this->snapshot($projection, fresh: true);
            $this->assertThrows(fn () => $this->bind($snapshot, 'k-'.md5($why)), DomainInvariantException::class, null);
        }
        $this->assertSame(0, DocumentSubjectBinding::query()->count());
        $this->assertSame(0, QuotationDocumentBinding::query()->count());
    }

    public function test_a_failed_bind_leaves_nothing_in_sales_either(): void
    {
        $snapshot = $this->snapshot($this->projection(['header' => ['net_total' => 'abc'] + $this->projection()['header']]));

        $this->assertThrows(fn () => $this->bind($snapshot), DomainInvariantException::class);
        $this->assertSame(0, QuotationDocumentBinding::query()->count());
        $this->assertSame(0, DocumentSubjectBinding::query()->count());
    }

    public function test_the_database_refuses_cross_company_and_mismatched_writes(): void
    {
        $snapshot = $this->snapshot();
        $other = DB::table('companies')->insertGetId(['name' => 'Other Co', 'created_at' => now(), 'updated_at' => now()]);
        $otherUser = DB::table('users')->insertGetId(['company_id' => $other, 'name' => 'O', 'email' => 'o@other.test', 'password' => 'x', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        $otherFamily = DB::table('quotation_families')->insertGetId(['company_id' => $other, 'created_by' => $otherUser, 'created_at' => now(), 'updated_at' => now()]);
        $otherRevision = DB::table('quotation_revisions')->insertGetId(['company_id' => $other, 'family_id' => $otherFamily, 'currency_id' => $this->pkr->id, 'created_by' => $otherUser, 'created_at' => now(), 'updated_at' => now()]);
        $foreignDocument = DB::table('document_subject_bindings')->insertGetId(['company_id' => $other, 'type_key' => 'sales.quotation.customer', 'schema_version' => 1, 'audience' => 'CUSTOMER',
            'owner_binding_identity' => 'quotation-snapshot:'.$snapshot->id, 'source_generation' => 1, 'bound_at' => now()]);
        $row = ['schema_version' => 1, 'projection' => json_encode($this->projection()), 'projection_digest' => str_repeat('a', 64), 'created_at' => now()];

        // A snapshot cannot name another company's revision, nor a revision under another family.
        $this->assertDbRefuses(fn () => DB::table('quotation_customer_document_snapshots')->insert(['company_id' => 1, 'family_id' => $otherFamily, 'revision_id' => $otherRevision] + $row), 'quotation_document_snapshots_revision_fk');
        $this->assertDbRefuses(fn () => DB::table('quotation_customer_document_snapshots')->insert(['company_id' => 1, 'family_id' => $this->revision->family_id + 999, 'revision_id' => $this->revision->id] + ['schema_version' => 2] + $row), 'quotation_document_snapshots_revision_fk');
        // A binding cannot point at another company's Documents identity, nor at one that does not belong to this snapshot and audience.
        $this->assertDbRefuses(fn () => DB::table('quotation_document_bindings')->insert(['company_id' => 1, 'snapshot_id' => $snapshot->id, 'document_binding_id' => $foreignDocument, 'audience' => 'CUSTOMER', 'created_at' => now()]), 'does not identify this quotation snapshot');
        $wrong = DocumentSubjectBinding::create(['type_key' => 'sales.quotation.customer', 'schema_version' => 1, 'audience' => 'CUSTOMER', 'owner_binding_identity' => 'quotation-snapshot:999999', 'source_generation' => 1, 'bound_at' => now()]);
        $this->assertDbRefuses(fn () => DB::table('quotation_document_bindings')->insert(['company_id' => 1, 'snapshot_id' => $snapshot->id, 'document_binding_id' => $wrong->id, 'audience' => 'CUSTOMER', 'created_at' => now()]), 'does not identify this quotation snapshot');
        $spare = QuotationRevision::create(['family_id' => $this->revision->family_id, 'currency_id' => $this->pkr->id, 'created_by' => $this->generator->id])->refresh();
        $this->assertDbRefuses(fn () => DB::table('quotation_customer_document_snapshots')->insert(['company_id' => 1, 'family_id' => $spare->family_id, 'revision_id' => $spare->id, 'schema_version' => 1, 'projection' => '[]',
            'projection_digest' => 'nothex', 'created_at' => now()]), 'quotation_document_snapshots_shape');
        $this->assertSame(0, QuotationDocumentBinding::query()->count());
    }

    public function test_one_snapshot_has_one_binding_per_audience_and_history_is_immutable(): void
    {
        $snapshot = $this->snapshot();
        $this->bind($snapshot);
        $binding = QuotationDocumentBinding::query()->firstOrFail();

        $this->assertDbRefuses(fn () => DB::table('quotation_document_bindings')->where('id', $binding->id)->update(['audience' => 'OTHER']), 'append-only');
        $this->assertDbRefuses(fn () => DB::table('quotation_document_bindings')->where('id', $binding->id)->delete(), 'append-only');
        $this->assertDbRefuses(fn () => DB::table('quotation_customer_document_snapshots')->where('id', $snapshot->id)->update(['projection' => '{}']), 'append-only');
        $this->assertDbRefuses(fn () => DB::table('quotation_customer_document_snapshots')->where('id', $snapshot->id)->delete(), 'append-only');
        $this->assertDbRefuses(fn () => DB::table('quotation_customer_document_snapshots')->insert(['company_id' => 1, 'family_id' => $this->revision->family_id, 'revision_id' => $this->revision->id, 'schema_version' => 1,
            'projection' => '{}', 'projection_digest' => str_repeat('b', 64), 'created_at' => now()]), 'quotation_document_snapshots_revision_unique');
    }

    public function test_another_companys_snapshot_is_simply_not_found(): void
    {
        try {
            app(BindQuotationDocument::class)($this->generator, 987654, 'k');
            $this->fail('Expected a refusal.');
        } catch (DomainInvariantException $refusal) {
            $this->assertSame('SNAPSHOT_NOT_FOUND', $refusal->reasonCode);
        }
    }
}
