<?php

namespace Tests\Feature\Modules\Sales;

use App\Models\Company;
use App\Models\Currency;
use App\Models\User;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Actions\CreateQuotationDraft;
use App\Modules\Sales\Actions\UpdateQuotationDraft;
use App\Modules\Sales\Support\Authority\DutyStatus;
use App\Modules\Sales\Support\Authority\SalesDuty;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** QL1b-1 — the read-only HTTP adapters over `ReadQuotationDraft` and `PreviewQuotation`: default-deny while STAGED, the independent duties, and tenant isolation. */
class QuotationDraftReviewApiTest extends TestCase
{
    use RefreshDatabase;

    private const VIEW = PermissionVocabulary::QUOTATION_VIEW;

    private const PREPARE = PermissionVocabulary::QUOTATION_PREPARE;

    private User $preparer;

    private int $revisionId;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        foreach ([self::VIEW, self::PREPARE] as $duty) {
            Permission::findOrCreate($duty, 'web');
        }
        $pkr = Currency::firstOrCreate(['code' => 'PKR'], ['name' => 'Rupee', 'symbol' => 'Rs', 'decimal_places' => 2]);
        Company::findOrFail(1)->update(['base_currency_id' => $pkr->id]);
        $this->preparer = User::factory()->create();
        $this->preparer->givePermissionTo([self::VIEW, self::PREPARE]);
        $this->activate([self::VIEW, self::PREPARE]);
        $party = Party::create(['code' => 'PROSPECT', 'name' => 'Prospect Ltd']);
        $draft = app(CreateQuotationDraft::class)($this->preparer, 'k-1', ['recipient_party_id' => $party->id, 'pricing_date' => now(Company::findOrFail(1)->timezone)->format('Y-m-d')]);
        $this->revisionId = $draft->revisionId;
        app(UpdateQuotationDraft::class)($this->preparer, $this->revisionId, $draft->inputGeneration, 'k-2', ['add_lines' => [
            ['source_kind' => 'MANUAL', 'description' => 'Survey', 'manual_measure' => 'job', 'offered_quantity' => '1', 'unit_price' => null],
        ]]);
        $this->deactivate();
    }

    /** @param  list<string>  $names */
    private function activate(array $names): void
    {
        $duties = array_map(fn (SalesDuty $duty): SalesDuty => in_array($duty->name, $names, true)
            ? new SalesDuty($duty->name, $duty->class, DutyStatus::Active, $duty->operation, $duty->projection, $duty->deliveryGate) : $duty, SalesDutyManifest::shipped()->all());
        $this->app->instance(SalesDutyManifest::class, new SalesDutyManifest($duties));
    }

    private function deactivate(): void
    {
        $this->app->instance(SalesDutyManifest::class, SalesDutyManifest::shipped());
    }

    public function test_every_caller_is_refused_while_the_duties_are_staged(): void
    {
        Sanctum::actingAs($this->preparer);

        $this->getJson("/api/v1/quotations/{$this->revisionId}")->assertForbidden();
        $this->getJson("/api/v1/quotations/{$this->revisionId}/preview")->assertForbidden();
    }

    public function test_unauthenticated_callers_are_refused(): void
    {
        $this->getJson("/api/v1/quotations/{$this->revisionId}")->assertUnauthorized();
        $this->getJson("/api/v1/quotations/{$this->revisionId}/preview")->assertUnauthorized();
    }

    public function test_a_view_holder_reads_safe_metadata_but_cannot_preview(): void
    {
        $this->activate([self::VIEW, self::PREPARE]);
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(self::VIEW);
        Sanctum::actingAs($viewer);

        $this->getJson("/api/v1/quotations/{$this->revisionId}")->assertOk()
            ->assertJsonPath('data.revision_id', $this->revisionId)->assertJsonPath('data.state', 'DRAFT')->assertJsonPath('data.line_count', 1)
            ->assertJsonMissingPath('data.lines');
        $this->getJson("/api/v1/quotations/{$this->revisionId}/preview")->assertForbidden();
    }

    public function test_a_prepare_holder_previews_without_cost_and_never_issue_ready(): void
    {
        $this->activate([self::VIEW, self::PREPARE]);
        $preparer = User::factory()->create();
        $preparer->givePermissionTo(self::PREPARE);
        Sanctum::actingAs($preparer);

        $this->getJson("/api/v1/quotations/{$this->revisionId}/preview")->assertOk()
            ->assertJsonPath('data.authority', 'PREVIEW_ONLY')->assertJsonPath('data.issue_ready', false)->assertJsonPath('data.tax.certainty', 'UNKNOWN')
            ->assertJsonPath('data.controls.evaluated', 0);
        $this->getJson("/api/v1/quotations/{$this->revisionId}")->assertForbidden();
    }

    public function test_an_unknown_revision_is_not_found(): void
    {
        $this->activate([self::VIEW, self::PREPARE]);
        Sanctum::actingAs($this->preparer);

        $this->getJson('/api/v1/quotations/999999')->assertNotFound();
        $this->getJson('/api/v1/quotations/999999/preview')->assertNotFound();
        $this->getJson('/api/v1/quotations/abc')->assertNotFound();
    }

    public function test_another_companys_quotation_is_not_found(): void
    {
        $this->activate([self::VIEW, self::PREPARE]);
        Sanctum::actingAs($this->preparer);
        $company = DB::table('companies')->insertGetId(['name' => 'Other Company', 'created_at' => now(), 'updated_at' => now()]);
        $this->app->instance(CompanyContext::class, new class($company) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });

        config(['app.debug' => false]);
        // The refusal is the framework's own not-found, identical to an unknown id apart from the id the caller sent; nothing says the quotation exists elsewhere.
        $unknown = $this->getJson('/api/v1/quotations/999999')->assertNotFound()->json('message');
        $other = $this->getJson("/api/v1/quotations/{$this->revisionId}")->assertNotFound()->json('message');
        $this->assertSame($unknown, str_replace((string) $this->revisionId, '999999', $other));
        $this->getJson("/api/v1/quotations/{$this->revisionId}/preview")->assertNotFound();
    }
}
