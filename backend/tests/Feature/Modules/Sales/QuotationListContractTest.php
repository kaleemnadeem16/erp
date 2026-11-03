<?php

namespace Tests\Feature\Modules\Sales;

use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Sales\Actions\CreateQuotationDraft;
use App\Modules\Sales\Actions\IssueQuotation;
use App\Modules\Sales\Actions\UpdateQuotationDraft;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Authority\DutyStatus;
use App\Modules\Sales\Support\Authority\SalesDuty;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as Duty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Modules\Sales\Concerns\BuildsIssuableQuotations;
use Tests\TestCase;

/**
 * The list's recipient name and net amount are decided PER ROW inside the list: the name by Party read (a current member and a Party visible under the company scope), the net by the
 * independent `sales.quotation.commercial.view` for that row's family (OWN or ALL) and only for an ISSUED revision. A hidden value is null exactly as an absent one is.
 */
class QuotationListContractTest extends TestCase
{
    use BuildsIssuableQuotations;
    use RefreshDatabase;

    private User $otherMaker;

    private QuotationRevision $mine;

    private QuotationRevision $theirs;

    private QuotationRevision $draft;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIssuing();
        Permission::findOrCreate(Duty::QUOTATION_COMMERCIAL_VIEW, 'web');
        $this->otherMaker = User::factory()->create();
        $this->otherMaker->givePermissionTo([Duty::QUOTATION_PREPARE, Duty::QUOTATION_VIEW]);
        $this->activate(true);
        $this->mine = $this->issued($this->maker);
        $this->theirs = $this->issued($this->otherMaker);
        $this->draft = $this->preparedDraft();
        $this->activate(true);
    }

    private function activate(bool $commercial): void
    {
        $on = [Duty::QUOTATION_PREPARE, Duty::QUOTATION_VIEW, Duty::QUOTATION_ISSUE, ...($commercial ? [Duty::QUOTATION_COMMERCIAL_VIEW] : [])];
        $this->app->instance(SalesDutyManifest::class, new SalesDutyManifest(array_map(fn (SalesDuty $d): SalesDuty => in_array($d->name, $on, true)
            ? new SalesDuty($d->name, $d->class, DutyStatus::Active, $d->operation, $d->projection, $d->deliveryGate, $d->scope) : $d, SalesDutyManifest::shipped()->all())));
    }

    private function issued(User $maker): QuotationRevision
    {
        $created = app(CreateQuotationDraft::class)($maker, $this->issueKey(), ['recipient_party_id' => $this->customer->id, 'pricing_date' => $this->today(), 'tax_estimate_date' => $this->today(),
            'valid_through_local_date' => now()->addMonth()->format('Y-m-d')]);
        app(UpdateQuotationDraft::class)($maker, $created->revisionId, $created->inputGeneration, $this->issueKey(), ['add_lines' => [
            ['source_kind' => 'MANUAL', 'description' => 'Survey', 'manual_measure' => 'job', 'offered_quantity' => '2', 'unit_price' => '100.0000']]]);
        app(IssueQuotation::class)($this->checker, $created->revisionId, QuotationRevision::findOrFail($created->revisionId)->input_generation, $this->issueKey());

        return QuotationRevision::findOrFail($created->revisionId);
    }

    private function viewer(array $duties, array $own = []): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($duties);
        foreach ($own as $duty) {
            AccessScopeLimit::create(['user_id' => $user->id, 'permission' => $duty, 'scope' => 'OWN']);
        }

        return $user;
    }

    /** @return array<int, array<string, mixed>> keyed by revision id */
    private function rows(User $as): array
    {
        Sanctum::actingAs($as);

        return collect($this->getJson('/api/v1/quotations?per_page=100')->assertOk()->json('data'))->keyBy('revision_id')->all();
    }

    public function test_commercial_view_all_shows_the_name_and_the_frozen_net_of_issued_rows_only(): void
    {
        $rows = $this->rows($this->viewer([Duty::QUOTATION_VIEW, Duty::QUOTATION_COMMERCIAL_VIEW]));

        $this->assertSame(['Customer Ltd', '200.0000'], [$rows[$this->mine->id]['recipient_name'], $rows[$this->mine->id]['net_amount']]);
        $this->assertSame(['Customer Ltd', '200.0000'], [$rows[$this->theirs->id]['recipient_name'], $rows[$this->theirs->id]['net_amount']]);
        $this->assertSame(['Customer Ltd', null], [$rows[$this->draft->id]['recipient_name'], $rows[$this->draft->id]['net_amount']], 'a list never reprices: a draft has no frozen net');
    }

    public function test_commercial_view_own_shows_the_net_only_on_the_actors_own_families_row_by_row(): void
    {
        $viewer = $this->viewer([Duty::QUOTATION_VIEW, Duty::QUOTATION_COMMERCIAL_VIEW, Duty::QUOTATION_PREPARE]);
        AccessScopeLimit::create(['user_id' => $viewer->id, 'permission' => Duty::QUOTATION_COMMERCIAL_VIEW, 'scope' => 'OWN']);
        // The viewer owns a family of their own, issued; the rest belong to others.
        $own = $this->issued($viewer);
        $rows = $this->rows($viewer);

        $this->assertSame('200.0000', $rows[$own->id]['net_amount']);
        $this->assertNull($rows[$this->mine->id]['net_amount'], 'visible in the list (view is ALL) but not commercially');
        $this->assertNull($rows[$this->theirs->id]['net_amount']);
        $this->assertSame('Customer Ltd', $rows[$this->mine->id]['recipient_name'], 'the name follows Party read, not the commercial duty');
    }

    public function test_view_own_with_commercial_all_cannot_widen_the_rows(): void
    {
        $viewer = $this->viewer([Duty::QUOTATION_VIEW, Duty::QUOTATION_COMMERCIAL_VIEW, Duty::QUOTATION_PREPARE], [Duty::QUOTATION_VIEW]);
        $own = $this->issued($viewer);
        $rows = $this->rows($viewer);

        $this->assertSame([$own->id], array_keys($rows), 'the commercial duty never adds a row the list would not show');
        $this->assertSame('200.0000', $rows[$own->id]['net_amount']);
    }

    public function test_without_the_duty_or_while_it_is_staged_the_net_is_null_never_a_refusal(): void
    {
        $plain = $this->viewer([Duty::QUOTATION_VIEW]);
        foreach ($this->rows($plain) as $row) {
            $this->assertNull($row['net_amount']);
            $this->assertSame('Customer Ltd', $row['recipient_name']);
        }
        $holder = $this->viewer([Duty::QUOTATION_VIEW, Duty::QUOTATION_COMMERCIAL_VIEW]);
        $this->activate(false);
        foreach ($this->rows($holder) as $row) {
            $this->assertNull($row['net_amount'], 'a STAGED duty is refused to every caller, even one holding the permission');
        }
    }

    public function test_the_hidden_values_cannot_be_probed_by_filter_or_sort(): void
    {
        Sanctum::actingAs($this->viewer([Duty::QUOTATION_VIEW]));
        $this->getJson('/api/v1/quotations?sort=net_amount')->assertUnprocessable();
        $this->getJson('/api/v1/quotations?sort=recipient_name')->assertUnprocessable();
        $this->getJson('/api/v1/quotations?filter[net_amount]=200')->assertUnprocessable();
        $this->getJson('/api/v1/quotations?filter[recipient_name]=Customer')->assertUnprocessable();
    }

    public function test_a_member_suspended_after_sign_in_gets_neither_the_rows_nor_the_values(): void
    {
        $viewer = $this->viewer([Duty::QUOTATION_VIEW, Duty::QUOTATION_COMMERCIAL_VIEW]);
        Sanctum::actingAs($viewer);
        $this->getJson('/api/v1/quotations')->assertOk();
        DB::table('users')->where('id', $viewer->id)->update(['status' => 'SUSPENDED']);
        $this->getJson('/api/v1/quotations')->assertForbidden();
    }
}
