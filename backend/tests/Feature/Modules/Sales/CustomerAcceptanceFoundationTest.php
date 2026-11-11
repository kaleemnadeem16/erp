<?php

namespace Tests\Feature\Modules\Sales;

use App\Modules\Sales\Actions\IssueQuotation;
use App\Modules\Sales\Models\CustomerAcceptance;
use App\Modules\Sales\Models\CustomerAcceptanceLine;
use App\Modules\Sales\Models\CustomerClaimEvent;
use App\Modules\Sales\Models\CustomerDecisionCase;
use App\Modules\Sales\Models\CustomerDecisionComponent;
use App\Modules\Sales\Models\CustomerDecisionEvidence;
use App\Modules\Sales\Models\CustomerEvidencePolicyVersion;
use App\Modules\Sales\Models\CustomerScopeClaimHead;
use App\Modules\Sales\Models\QuotationIssuedLine;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Modules\Sales\Concerns\BuildsIssuableQuotations;
use Tests\TestCase;

class CustomerAcceptanceFoundationTest extends TestCase
{
    use BuildsIssuableQuotations;
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $this->setUpIssuing();
        $revision = $this->preparedDraft();
        app(IssueQuotation::class)($this->checker, $revision->id, $revision->input_generation, 'foundation-issue');
        $line = QuotationIssuedLine::query()->where('revision_id', $revision->id)->orderBy('id')->firstOrFail();
        $case = CustomerDecisionCase::create(['party_id' => $this->customer->id, 'intake_identity' => 'Synthetic consent', 'recorded_by' => $this->checker->id]);
        $component = CustomerDecisionComponent::create(['case_id' => $case->id, 'intent_identity' => 'survey', 'family_id' => $line->family_id, 'original_scope_anchor_id' => $line->logical_scope_id, 'recorded_by' => $this->checker->id]);
        $evidence = CustomerDecisionEvidence::create(['case_id' => $case->id, 'source_kind' => 'EMAIL', 'authority_verification_basis' => 'DOCUMENT_ON_FILE', 'customer_representative_snapshot' => 'Synthetic buyer', 'occurrence_precision' => 'UNKNOWN', 'factual_description' => 'The named buyer agrees to the issued scope unchanged.', 'observation_state' => 'OBSERVED', 'recorded_by' => $this->checker->id]);
        $policy = CustomerEvidencePolicyVersion::create(['version' => 1, 'policy_code' => 'WRITTEN_VERIFIED_FULL_ONLY_V1', 'source_requirements' => ['verbal' => false, 'verification' => ['DOCUMENT_ON_FILE', 'CONFIRMED_BY_SELLER']]]);
        $head = CustomerScopeClaimHead::create(['family_id' => $line->family_id, 'scope_anchor_id' => $line->logical_scope_id]);
        $acceptance = ['case_id' => $case->id, 'decision_component_id' => $component->id, 'revision_id' => $revision->id, 'consent_evidence_id' => $evidence->id, 'policy_version_id' => $policy->id, 'actor_id' => $this->checker->id, 'intent_digest' => str_repeat('a', 64)];

        return compact('line', 'head', 'acceptance');
    }

    private function refuses(Closure $write): void
    {
        try {
            DB::transaction($write);
            $this->fail('Database must refuse invalid acceptance storage.');
        } catch (QueryException $e) {
            $this->assertContains($e->errorInfo[0], ['23514', '23503', '23505']);
        }
    }

    public function test_complete_acceptance_consumes_one_full_scope_and_preserves_frozen_terms(): void
    {
        $f = $this->fixture();
        $a = CustomerAcceptance::create($f['acceptance']);
        CustomerAcceptanceLine::create(['acceptance_id' => $a->id, 'issued_line_id' => $f['line']->id]);
        CustomerClaimEvent::create(['acceptance_id' => $a->id, 'claim_head_id' => $f['head']->id]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->assertSame(1, $f['head']->refresh()->consumed);
        $this->assertSame(1, $f['head']->generation);
        $this->assertSame('2.0000', $f['line']->refresh()->offered_quantity);
        $this->assertSame('200.0000', $f['line']->net);
        foreach (['customer_evidence_policy_versions', 'customer_acceptances', 'customer_acceptance_lines', 'customer_claim_events'] as $table) {
            $this->refuses(fn () => DB::table($table)->update(['company_id' => 1]));
            $this->refuses(fn () => DB::table($table)->delete());
            $this->refuses(fn () => DB::statement("TRUNCATE {$table} CASCADE"));
        }
        $this->refuses(fn () => $f['head']->update(['consumed' => 0]));
        $this->refuses(fn () => CustomerClaimEvent::create(['acceptance_id' => $a->id, 'claim_head_id' => $f['head']->id]));
        $this->refuses(fn () => DB::statement('TRUNCATE customer_scope_claim_heads CASCADE'));
    }

    public function test_incomplete_acceptance_cannot_commit_and_rolls_back_all_evidence(): void
    {
        $f = $this->fixture();
        $this->refuses(function () use ($f): void {
            CustomerAcceptance::create($f['acceptance']);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
        $this->assertSame(0, CustomerAcceptance::query()->count());
        $this->assertSame(0, $f['head']->refresh()->consumed);
    }

    public function test_baselines_cannot_invent_consumption_and_policies_cannot_enable_verbal(): void
    {
        $f = $this->fixture();
        $this->refuses(fn () => CustomerScopeClaimHead::create(['family_id' => $f['line']->family_id, 'scope_anchor_id' => $f['line']->logical_scope_id, 'consumed' => 1, 'generation' => 1, 'selected_scope_id' => $f['line']->logical_scope_id]));
        $this->refuses(fn () => CustomerEvidencePolicyVersion::create(['version' => 2, 'policy_code' => 'VERBAL', 'source_requirements' => ['verbal' => true]]));
    }
}
