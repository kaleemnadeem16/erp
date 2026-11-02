<?php

namespace Tests\Feature\Modules\Sales;

use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as P;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Modules\Sales\Concerns\BuildsIssuableQuotations;
use Tests\TestCase;

/**
 * The QL3 issue HTTP surface: readiness (prepare + view), issue (issue + view, typed Idempotency-Key, expected_generation) and the issued record (view; totals only
 * for prepare/issue holders). Default-deny while STAGED, non-disclosing 404, and nothing protected in any response.
 */
class QuotationIssueApiTest extends TestCase
{
    use BuildsIssuableQuotations;
    use RefreshDatabase;

    private const PROTECTED = ['fingerprint', 'controls_outcome', 'cost', 'margin', 'tax_evidence', 'tax_generation', 'numbering_allocation', 'policy_version', 'storage'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIssuing();
    }

    private function url(int $revision, string $tail): string
    {
        return "/api/v1/quotations/{$revision}{$tail}";
    }

    private function assertNothingProtected(string $json): void
    {
        foreach (self::PROTECTED as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $json);
        }
    }

    public function test_every_issue_route_is_refused_while_the_duties_are_staged(): void
    {
        $draft = $this->preparedDraft();
        $this->app->instance(SalesDutyManifest::class, SalesDutyManifest::shipped());
        Sanctum::actingAs($this->checker);
        $this->getJson($this->url($draft->id, '/issue-readiness'))->assertForbidden();
        $this->postJson($this->url($draft->id, '/issue'), ['expected_generation' => $draft->input_generation], ['Idempotency-Key' => 'k'])->assertForbidden();
        $this->getJson($this->url($draft->id, '/issued'))->assertForbidden();
    }

    public function test_readiness_issue_replay_and_the_issued_record(): void
    {
        $draft = $this->preparedDraft();
        $generation = QuotationRevision::findOrFail($draft->id)->input_generation;

        Sanctum::actingAs($this->maker);
        $ready = $this->getJson($this->url($draft->id, '/issue-readiness'))->assertOk()
            ->assertJsonPath('data.revision_id', $draft->id)->assertJsonPath('data.input_generation', $generation)->assertJsonPath('data.issue_ready', true);
        $this->assertNothingProtected((string) $ready->getContent());
        // The maker holds prepare, not issue: the issue route is refused before anything is looked up.
        $this->postJson($this->url($draft->id, '/issue'), ['expected_generation' => $generation], ['Idempotency-Key' => 'maker-try'])->assertForbidden();

        Sanctum::actingAs($this->checker);
        $this->postJson($this->url($draft->id, '/issue'), ['expected_generation' => $generation])->assertStatus(422)->assertJsonPath('reason_code', 'REQUEST_KEY_REQUIRED');
        $this->postJson($this->url($draft->id, '/issue'), [], ['Idempotency-Key' => 'no-generation'])->assertStatus(422)->assertJsonValidationErrors('expected_generation');
        $this->postJson($this->url($draft->id, '/issue'), ['expected_generation' => $generation + 7], ['Idempotency-Key' => 'stale'])
            ->assertStatus(422)->assertJsonPath('reason_code', 'QUOTATION_DRAFT_STALE');

        $first = $this->postJson($this->url($draft->id, '/issue'), ['expected_generation' => $generation], ['Idempotency-Key' => 'issue-1'])->assertCreated()
            ->assertJsonPath('data.revision_id', $draft->id)->assertJsonPath('data.replayed', false);
        $number = $first->json('data.official_number');
        $this->assertNotEmpty($number);
        $this->assertNothingProtected((string) $first->getContent());
        $this->postJson($this->url($draft->id, '/issue'), ['expected_generation' => $generation], ['Idempotency-Key' => 'issue-1'])->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('data.official_number', $number)->assertJsonPath('data.replayed', true);
        $this->postJson($this->url($draft->id, '/issue'), ['expected_generation' => $generation + 1], ['Idempotency-Key' => 'issue-1'])
            ->assertStatus(409)->assertJsonPath('reason_code', 'REQUEST_KEY_REUSED');

        // The issued record: the issuer sees the settled totals; a view-only holder sees the header without them.
        $issued = $this->getJson($this->url($draft->id, '/issued'))->assertOk()->assertJsonPath('data.official_number', $number)
            ->assertJsonPath('data.prepared_by', $this->maker->id)->assertJsonPath('data.issued_by', $this->checker->id)->assertJsonPath('data.totals.net_total', '200.0000');
        $this->assertNothingProtected((string) $issued->getContent());
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(P::QUOTATION_VIEW);
        Sanctum::actingAs($viewer);
        $this->getJson($this->url($draft->id, '/issued'))->assertOk()->assertJsonPath('data.official_number', $number)->assertJsonPath('data.totals', null);
        // Readiness is for drafts only; an issued revision is no longer assessable (and a view-only holder may not ask at all).
        $this->getJson($this->url($draft->id, '/issue-readiness'))->assertForbidden();
    }

    public function test_hidden_unknown_and_unissued_revisions_are_the_same_not_found(): void
    {
        $draft = $this->preparedDraft();
        $issued = $this->preparedDraft();
        Sanctum::actingAs($this->checker);
        $this->postJson($this->url($issued->id, '/issue'), ['expected_generation' => QuotationRevision::findOrFail($issued->id)->input_generation], ['Idempotency-Key' => 'issue-h'])
            ->assertCreated();

        // A viewer limited to OWN who owns neither revision: the issued one is hidden.
        $stranger = User::factory()->create();
        $stranger->givePermissionTo([P::QUOTATION_VIEW, P::QUOTATION_ISSUE]);
        AccessScopeLimit::create(['user_id' => $stranger->id, 'permission' => P::QUOTATION_VIEW, 'scope' => 'OWN']);
        Sanctum::actingAs($stranger);
        $hidden = $this->getJson($this->url($issued->id, '/issued'))->assertNotFound();
        $unknown = $this->getJson($this->url(999999, '/issued'))->assertNotFound();
        $this->assertSame($unknown->json('message'), $hidden->json('message'));
        $this->postJson($this->url($issued->id, '/issue'), ['expected_generation' => 1], ['Idempotency-Key' => 'stranger'])->assertNotFound();

        // The unrestricted checker: a draft has no issued record yet, the same not-found as an unknown id.
        Sanctum::actingAs($this->checker);
        $this->assertSame($unknown->json('message'), $this->getJson($this->url($draft->id, '/issued'))->assertNotFound()->json('message'));
    }
}
