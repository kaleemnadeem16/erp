<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Modules\Sales\Actions\CreateQuotationDraft;
use App\Modules\Sales\Actions\CreateQuotationRevision;
use App\Modules\Sales\Actions\IssueQuotation;
use App\Modules\Sales\Actions\IssueQuotationReplacement;
use App\Modules\Sales\Actions\PreviewCustomerAcceptance;
use App\Modules\Sales\Actions\RecordCustomerAcceptance;
use App\Modules\Sales\Actions\UpdateQuotationDraft;
use App\Modules\Sales\Actions\WithdrawQuotationOffer;
use App\Modules\Sales\Models\CustomerDecisionCase;
use App\Modules\Sales\Models\CustomerDecisionComponent;
use App\Modules\Sales\Models\CustomerDecisionEvidence;
use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Models\QuotationIssuedLine;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as P;
use App\Modules\Sales\Support\Quotation\Issue\QuotationControlsProvider;
use App\Modules\Sales\Support\Quotation\Issue\QuotationTaxProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Feature\Security\Concerns\TwoSessionRace;
use Tests\Support\Sales\FakeQuotationControlsProvider;
use Tests\Support\Sales\FakeQuotationTaxProvider;
use Tests\TestCase;

/** Real committed, independent PostgreSQL sessions; controls prove each blocked Action can otherwise complete. */
class CustomerAcceptanceRaceTest extends TestCase
{
    use RefreshDatabase;
    use TwoSessionRace;

    private function fixture(bool $choices = false): array
    {
        $pdo = $this->raceSession();
        $prefix = 'ca'.bin2hex(random_bytes(3));
        $r = $this->raceCompany($pdo, $prefix);
        $actor = $this->raceUser($pdo, $r['company'], $prefix, 'actor');
        $names = [P::QUOTATION_PREPARE, P::QUOTATION_VIEW, P::QUOTATION_ISSUE, P::QUOTATION_WITHDRAW, P::CUSTOMER_DECISION_ACCEPT, P::CUSTOMER_DECISION_VIEW];
        foreach ($names as $duty) {
            $this->raceGrant($pdo, $r['company'], $actor, $duty);
        }
        $pdo->exec("update companies set timezone='Asia/Karachi' where id={$r['company']}");
        $this->raceBindCompany($r['company']);
        $this->app->instance(SalesDutyManifest::class, SalesDutyManifest::shipped()->withActivated($names));
        $this->app->instance(QuotationTaxProvider::class, new FakeQuotationTaxProvider);
        $this->app->instance(QuotationControlsProvider::class, new FakeQuotationControlsProvider);
        $data = $this->raceCommitted(function () use ($actor, $r, $choices): array {
            $user = User::findOrFail($actor);
            $party = (int) DB::table('parties')->where('company_id', $r['company'])->value('id');
            $today = now('Asia/Karachi')->toDateString();
            $draft = app(CreateQuotationDraft::class)($user, 'draft', ['recipient_party_id' => $party, 'pricing_date' => $today, 'tax_estimate_date' => $today, 'valid_through_local_date' => now('Asia/Karachi')->addMonth()->toDateString()]);
            app(UpdateQuotationDraft::class)($user, $draft->revisionId, $draft->inputGeneration, 'lines', ['add_lines' => $choices ? [
                ['source_kind' => 'MANUAL', 'description' => 'Synthetic option A', 'manual_measure' => 'job', 'offered_quantity' => '1', 'unit_price' => '100.0000', 'membership' => 'CHOOSE_ONE', 'choice_group_ref' => 'choice', 'is_baseline' => true],
                ['source_kind' => 'MANUAL', 'description' => 'Synthetic option B', 'manual_measure' => 'job', 'offered_quantity' => '1', 'unit_price' => '110.0000', 'membership' => 'CHOOSE_ONE', 'choice_group_ref' => 'choice'],
            ] : [['source_kind' => 'MANUAL', 'description' => 'Synthetic full scope', 'manual_measure' => 'job', 'offered_quantity' => '1', 'unit_price' => '100.0000']]]);
            $revision = QuotationRevision::findOrFail($draft->revisionId);
            app(IssueQuotation::class)($user, $revision->id, $revision->input_generation, 'issue');
            $line = QuotationIssuedLine::where('revision_id', $revision->id)->firstOrFail();
            $case = CustomerDecisionCase::create(['party_id' => $party, 'intake_identity' => 'Synthetic race consent', 'recorded_by' => $actor]);
            $component = CustomerDecisionComponent::create(['case_id' => $case->id, 'intent_identity' => 'scope', 'family_id' => $line->family_id, 'original_scope_anchor_id' => $line->logical_scope_id, 'recorded_by' => $actor]);
            $e = CustomerDecisionEvidence::create(['case_id' => $case->id, 'source_kind' => 'EMAIL', 'authority_verification_basis' => 'DOCUMENT_ON_FILE', 'customer_representative_snapshot' => 'Synthetic buyer', 'occurrence_precision' => 'UNKNOWN', 'factual_description' => 'The customer agrees to the full issued scope unchanged.', 'observation_state' => 'OBSERVED', 'recorded_by' => $actor]);
            $args = [$revision->id, $component->id, $e->id, $line->id, 0, 0];
            $token = app(PreviewCustomerAcceptance::class)($user, ...[...$args, $choices ? 0 : null])['preview_token'];
            $next = app(CreateQuotationRevision::class)($user, $revision->id, 'next');
            $draftLine = QuotationDraftLine::where('revision_id', $next->revisionId)->firstOrFail();
            $updated = app(UpdateQuotationDraft::class)($user, $next->revisionId, $next->inputGeneration, 'reprice', ['pricing_date' => $today, 'tax_estimate_date' => $today, 'valid_through_local_date' => now('Asia/Karachi')->addMonth()->toDateString(), 'update_lines' => [['id' => $draftLine->id, 'unit_price' => '110.0000']]]);

            return ['choice' => $choices ? 0 : null, 'args' => $args, 'token' => $token, 'revision' => $revision->id, 'next' => $updated->revisionId, 'generation' => $updated->inputGeneration];
        });

        return $data + compact('pdo', 'r', 'actor');
    }

    private function accept(array $f, string $key = 'accept'): \Closure
    {
        return fn () => app(RecordCustomerAcceptance::class)(User::findOrFail($f['actor']), $key, ...[...$f['args'], $f['choice'], $f['token'], true]);
    }

    private function lifecycle(array $f, bool $replace): \Closure
    {
        return $replace
            ? fn () => app(IssueQuotationReplacement::class)(User::findOrFail($f['actor']), $f['revision'], $f['next'], $f['generation'], 'replace', 'Synthetic replacement')
            : fn () => app(WithdrawQuotationOffer::class)(User::findOrFail($f['actor']), $f['revision'], 'withdraw', 'Synthetic withdrawal');
    }

    private function authorityGate(array $f): array
    {
        return ['select company_id from company_access_generations order by company_id for update', "select company_id from sales_authority_heads where company_id={$f['r']['company']} for update"];
    }

    /** Run the holder's real Action in the holder's transaction, so its locks and the child's form the actual race. */
    private function onHolder(PDO $holder, callable $work): void
    {
        $original = config('database.default');
        config(['database.connections.race_holder' => config('database.connections.testing'), 'database.default' => 'race_holder']);
        DB::connection()->setPdo($holder);
        // Adopt the harness-owned outer transaction; real Actions use savepoints and leave its commit to raceAction.
        (new \ReflectionProperty(DB::connection(), 'transactions'))->setValue(DB::connection(), 1);
        try {
            $work();
        } finally {
            config(['database.default' => $original]);
            DB::purge('race_holder');
        }
    }

    public function test_controls_acceptance_withdrawal_and_replacement_succeed_after_an_unchanged_gate(): void
    {
        foreach (['accept', 'withdraw', 'replace'] as $operation) {
            $f = $this->fixture();
            try {
                $o = $this->raceAction($f['pdo'], $this->authorityGate($f), $operation === 'accept' ? $this->accept($f) : $this->lifecycle($f, $operation === 'replace'), fn () => null);
                $this->assertTrue($o['success'], (string) $o['message']);
            } finally {
                $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
            }
        }
    }

    public function test_acceptance_racing_withdraw_or_replace_has_one_winner_in_both_orders(): void
    {
        foreach ([false, true] as $replace) {
            foreach ([false, true] as $acceptFirst) {
                $f = $this->fixture();
                try {
                    $child = $acceptFirst ? $this->lifecycle($f, $replace) : $this->accept($f);
                    $winner = $acceptFirst ? $this->accept($f) : $this->lifecycle($f, $replace);
                    $o = $this->raceAction($f['pdo'], $this->authorityGate($f), $child, fn (PDO $h) => $this->onHolder($h, $winner));
                    $this->assertFalse($o['success'], 'The losing operation must refuse.');
                    $this->assertStringContainsString($acceptFirst ? 'customer decision already stands' : 'currently available', (string) $o['message']);
                    $this->assertSame($acceptFirst ? '1' : '0', (string) $f['pdo']->query("select count(*) from customer_acceptances where company_id={$f['r']['company']}")->fetchColumn());
                } finally {
                    $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
                }
            }
        }
    }

    public function test_two_acceptances_of_one_offer_commit_exactly_one_business_fact(): void
    {
        $f = $this->fixture();
        try {
            $o = $this->raceAction($f['pdo'], $this->authorityGate($f), $this->accept($f, 'second'), fn (PDO $h) => $this->onHolder($h, $this->accept($f, 'first')));
            $this->assertTrue($o['success'], (string) $o['message']); // Same business intent replays, never a second fact.
            $this->assertSame('1', (string) $f['pdo']->query("select count(*) from customer_acceptances where company_id={$f['r']['company']}")->fetchColumn());
            $this->assertSame('1', (string) $f['pdo']->query("select sum(consumed) from customer_scope_claim_heads where company_id={$f['r']['company']}")->fetchColumn());
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    private function anotherConsent(array $f, bool $alternative = false): array
    {
        return $this->raceCommitted(function () use ($f, $alternative): array {
            $l = QuotationIssuedLine::where('revision_id', $f['revision'])->orderBy('id', $alternative ? 'desc' : 'asc')->firstOrFail();
            $original = CustomerDecisionComponent::findOrFail($f['args'][1]);
            $case = CustomerDecisionCase::create(['party_id' => CustomerDecisionCase::findOrFail($original->case_id)->party_id, 'intake_identity' => 'Second synthetic consent', 'recorded_by' => $f['actor']]);
            $component = CustomerDecisionComponent::create(['case_id' => $case->id, 'intent_identity' => 'second', 'family_id' => $l->family_id, 'original_scope_anchor_id' => $l->logical_scope_id, 'recorded_by' => $f['actor']]);
            $e = CustomerDecisionEvidence::create(['case_id' => $case->id, 'source_kind' => 'EMAIL', 'authority_verification_basis' => 'DOCUMENT_ON_FILE', 'customer_representative_snapshot' => 'Synthetic buyer', 'occurrence_precision' => 'UNKNOWN', 'factual_description' => 'A second agreement to this scope.', 'observation_state' => 'OBSERVED', 'recorded_by' => $f['actor']]);
            $args = [$f['revision'], $component->id, $e->id, $l->id, 0, 0];
            $token = app(PreviewCustomerAcceptance::class)(User::findOrFail($f['actor']), ...[...$args, $f['choice']])['preview_token'];

            return array_replace($f, ['args' => $args, 'token' => $token]);
        });
    }

    public function test_competing_consents_and_competing_choice_options_have_exactly_one_winner(): void
    {
        foreach ([false, true] as $choice) {
            $f = $this->fixture($choice);
            try {
                $second = $this->anotherConsent($f, $choice);
                $o = $this->raceAction($f['pdo'], $this->authorityGate($f), $this->accept($second, 'second'), fn (PDO $h) => $this->onHolder($h, $this->accept($f, 'first')));
                $this->assertFalse($o['success']);
                $this->assertStringContainsString($choice ? 'choice in this group' : 'scope is already claimed', (string) $o['message']);
                $this->assertSame('1', (string) $f['pdo']->query("select count(*) from customer_acceptances where company_id={$f['r']['company']}")->fetchColumn());
                $this->assertSame($choice ? '2' : '1', (string) $f['pdo']->query("select count(*) from customer_claim_events where company_id={$f['r']['company']}")->fetchColumn());
            } finally {
                $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
            }
        }
    }

    public function test_control_and_revocation_or_suspension_before_decision_and_replay(): void
    {
        foreach (['control', 'revoke', 'suspend'] as $change) {
            foreach ([false, true] as $replay) {
                $f = $this->fixture();
                try {
                    if ($replay) {
                        $this->raceCommitted($this->accept($f));
                    }
                    $o = $this->raceAction($f['pdo'], 'select company_id from company_access_generations order by company_id for update', $this->accept($f), function (PDO $h) use ($f, $change): void {
                        if ($change === 'revoke') {
                            $this->raceRevoke($h, $f['actor'], P::CUSTOMER_DECISION_ACCEPT);
                        } elseif ($change === 'suspend') {
                            $h->exec("update users set status='SUSPENDED' where id={$f['actor']}");
                        }
                    });
                    $this->assertSame($change === 'control', $o['success'], (string) $o['message']);
                    if ($change !== 'control') {
                        $this->assertStringContainsString('not permitted', (string) $o['message']);
                    }
                } finally {
                    $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
                }
            }
        }
    }
}
