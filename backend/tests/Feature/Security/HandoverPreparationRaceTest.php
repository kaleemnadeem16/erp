<?php

namespace Tests\Feature\Security;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Sales\Actions\AssessAcceptanceReadiness;
use App\Modules\Sales\Actions\CreateQuotationRevision;
use App\Modules\Sales\Actions\IssueQuotationReplacement;
use App\Modules\Sales\Actions\PrepareHandoverPlan;
use App\Modules\Sales\Actions\PreviewHandover;
use App\Modules\Sales\Actions\UpdateQuotationDraft;
use App\Modules\Sales\Actions\WithdrawQuotationOffer;
use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Support\PermissionVocabulary as P;
use App\Modules\Sales\Support\Quotation\Issue\QuotationControlsProvider;
use App\Modules\Sales\Support\Quotation\Issue\QuotationTaxProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Feature\Modules\Sales\Concerns\BuildsHandoverFixture;
use Tests\Feature\Security\Concerns\TwoSessionRace;
use Tests\Support\Sales\FakeQuotationControlsProvider;
use Tests\Support\Sales\FakeQuotationTaxProvider;
use Tests\TestCase;

class HandoverPreparationRaceTest extends TestCase
{
    use BuildsHandoverFixture;
    use RefreshDatabase;
    use TwoSessionRace;

    private function fixture(): array
    {
        $pdo = $this->raceSession();
        $prefix = 'hp'.bin2hex(random_bytes(3));
        $r = $this->raceCompany($pdo, $prefix);
        $actor = $this->raceUser($pdo, $r['company'], $prefix, 'actor');
        foreach ($this->handoverDuties() as $duty) {
            $this->raceGrant($pdo, $r['company'], $actor, $duty);
        }
        $pdo->exec("update companies set timezone='Asia/Karachi' where id={$r['company']}");
        $this->raceBindCompany($r['company']);
        $this->activateHandover();
        $this->app->instance(QuotationTaxProvider::class, new FakeQuotationTaxProvider);
        $this->app->instance(QuotationControlsProvider::class, new FakeQuotationControlsProvider);
        $data = $this->raceCommitted(function () use ($actor, $r): array {
            $user = User::findOrFail($actor);
            $party = (int) DB::table('parties')->where('company_id', $r['company'])->value('id');
            $data = $this->serviceAcceptance($user, $party);
            $token = app(PreviewHandover::class)($user, $data['acceptance'], $data['inputs'])['preview_token'];
            $next = app(CreateQuotationRevision::class)($user, $data['revision'], 'successor');
            $line = QuotationDraftLine::where('revision_id', $next->revisionId)->firstOrFail();
            $today = now('Asia/Karachi')->toDateString();
            $updated = app(UpdateQuotationDraft::class)($user, $next->revisionId, $next->inputGeneration, 'reprice', ['pricing_date' => $today, 'tax_estimate_date' => $today, 'valid_through_local_date' => now('Asia/Karachi')->addMonth()->toDateString(), 'update_lines' => [['id' => $line->id, 'unit_price' => '110.0000']]]);

            return $data + ['token' => $token, 'next' => $updated->revisionId, 'generation' => $updated->inputGeneration];
        });

        return $data + compact('pdo', 'r', 'actor');
    }

    private function prepare(array $f, string $key = 'prepare', bool $changed = false): \Closure
    {
        return fn () => app(PrepareHandoverPlan::class)(User::findOrFail($f['actor']), $key, $f['acceptance'], $changed ? array_replace($f['inputs'], ['instructions' => 'Different operational intent']) : $f['inputs'], $f['token']);
    }

    private function gate(array $f): array
    {
        return ['select company_id from company_access_generations order by company_id for update', "select company_id from sales_authority_heads where company_id={$f['r']['company']} for update"];
    }

    private function countPlans(array $f): int
    {
        return (int) $f['pdo']->query("select count(*) from handover_plans where company_id={$f['r']['company']}")->fetchColumn();
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

    public function test_control_a_current_preparer_records_a_pending_plan_after_an_unchanged_gate(): void
    {
        $f = $this->fixture();
        try {
            $o = $this->raceAction($f['pdo'], $this->gate($f), $this->prepare($f), fn () => null);
            $this->assertTrue($o['success'], (string) $o['message']);
            $this->assertSame(1, $this->countPlans($f));
            $this->assertSame(0, (int) $f['pdo']->query("select count(*) from service_jobs where company_id={$f['r']['company']}")->fetchColumn());
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_two_simultaneous_calls_create_one_plan_and_changed_intent_is_refused(): void
    {
        foreach ([false, true] as $changed) {
            $f = $this->fixture();
            try {
                $o = $this->raceAction($f['pdo'], $this->gate($f), $this->prepare($f, 'second', $changed), fn (PDO $h) => $this->onHolder($h, $this->prepare($f, 'first')));
                $this->assertSame(! $changed, $o['success'], (string) $o['message']);
                if ($changed) {
                    $this->assertStringContainsString('different full-scope', (string) $o['message']);
                }
                $this->assertSame(1, $this->countPlans($f));
            } finally {
                $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
            }
        }
    }

    public function test_prepare_racing_withdraw_or_replace_preserves_acceptance_in_both_orders(): void
    {
        foreach ([false, true] as $replace) {
            foreach ([false, true] as $prepareFirst) {
                $f = $this->fixture();
                try {
                    $lifecycle = function () use ($f, $replace): void {
                        $user = User::findOrFail($f['actor']);
                        if ($replace) {
                            app(IssueQuotationReplacement::class)($user, $f['revision'], $f['next'], $f['generation'], 'replace', 'Synthetic replacement');
                        } else {
                            app(WithdrawQuotationOffer::class)($user, $f['revision'], 'withdraw', 'Synthetic withdrawal');
                        }
                    };
                    if ($prepareFirst) {
                        $o = $this->raceAction($f['pdo'], $this->gate($f), $lifecycle, fn (PDO $h) => $this->onHolder($h, $this->prepare($f)));
                        $this->assertFalse($o['success']);
                        $this->assertStringContainsString('customer decision already stands', (string) $o['message']);
                    } else {
                        $o = $this->raceAction($f['pdo'], $this->gate($f), $this->prepare($f), function (PDO $h) use ($lifecycle): void {
                            $this->onHolder($h, function () use ($lifecycle): void {
                                try {
                                    $lifecycle();
                                    $this->fail('An accepted offer cannot be withdrawn/replaced.');
                                } catch (DomainInvariantException $e) {
                                    $this->assertStringContainsString('customer decision already stands', $e->getMessage());
                                }
                            });
                        });
                        $this->assertTrue($o['success'], (string) $o['message']);
                    }
                    $this->assertSame(1, $this->countPlans($f));
                    $this->assertSame(0, (int) $f['pdo']->query("select count(*) from quotation_dispositions where company_id={$f['r']['company']}")->fetchColumn());
                } finally {
                    $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
                }
            }
        }
    }

    public function test_control_revocation_and_suspension_are_decided_before_mutation_and_replay(): void
    {
        foreach (['control', 'revoke', 'suspend'] as $change) {
            foreach ([false, true] as $replay) {
                $f = $this->fixture();
                try {
                    if ($replay) {
                        $this->raceCommitted($this->prepare($f));
                    }
                    $o = $this->raceAction($f['pdo'], $this->gate($f), $this->prepare($f), function (PDO $h) use ($f, $change): void {
                        if ($change === 'revoke') {
                            $this->raceRevoke($h, $f['actor'], P::HANDOVER_PREPARE);
                        } elseif ($change === 'suspend') {
                            $h->exec("update users set status='SUSPENDED' where id={$f['actor']}");
                        }
                    });
                    $this->assertSame($change === 'control', $o['success'], (string) $o['message']);
                    if ($change !== 'control') {
                        $this->assertStringContainsString('not permitted', (string) $o['message']);
                    }
                    $this->assertSame($replay || $change === 'control' ? 1 : 0, $this->countPlans($f));
                } finally {
                    $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
                }
            }
        }
    }

    public function test_contact_becoming_unavailable_while_preparing_refuses_the_stale_review(): void
    {
        $f = $this->fixture();
        try {
            $before = $this->raceCommitted(fn () => app(AssessAcceptanceReadiness::class)(User::findOrFail($f['actor']), $f['acceptance'], 'SERVICE_JOB', $f['inputs']));
            $this->assertTrue($before['preparation_ready']);
            $o = $this->raceAction($f['pdo'], $this->gate($f), $this->prepare($f), fn (PDO $h) => $h->exec("update party_contacts set deleted_at=now() where id={$f['contact']}"));
            $this->assertFalse($o['success']);
            $this->assertStringContainsString('Review this acceptance again', (string) $o['message']);
            $after = $this->raceCommitted(fn () => app(AssessAcceptanceReadiness::class)(User::findOrFail($f['actor']), $f['acceptance'], 'SERVICE_JOB', $f['inputs']));
            $this->assertFalse($after['preparation_ready']);
            $this->assertContains('SERVICE_CONTACT_REQUIRED', $after['missing']);
            $this->assertSame(0, $this->countPlans($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }
}
