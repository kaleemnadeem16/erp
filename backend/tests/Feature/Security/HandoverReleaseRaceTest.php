<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Modules\Sales\Actions\PrepareHandoverPlan;
use App\Modules\Sales\Actions\PreviewHandover;
use App\Modules\Sales\Actions\PreviewServiceRelease;
use App\Modules\Sales\Actions\ReleaseHandoverComponent;
use App\Modules\Sales\Support\Handover\Release\ReleasedServiceReceiver;
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
use Tests\Support\Sales\FakeReleasedServiceReceiver;
use Tests\Support\Sales\ScopedReleaseFixtures;
use Tests\TestCase;

/**
 * Phase 5 Batch 1: two real PostgreSQL sessions race to release the same prepared stage. Exactly one release event and one receiver link may result, and the loser resolves to
 * the winner's single outcome (same business intent) whatever its key or actor. The receiver is a durable fake so a forked process and the holder session see the same links.
 */
class HandoverReleaseRaceTest extends TestCase
{
    use BuildsHandoverFixture;
    use RefreshDatabase;
    use ScopedReleaseFixtures;
    use TwoSessionRace;

    protected function handoverDuties(): array
    {
        return [P::QUOTATION_PREPARE, P::QUOTATION_VIEW, P::QUOTATION_ISSUE, P::QUOTATION_WITHDRAW, P::CUSTOMER_DECISION_ACCEPT, P::CUSTOMER_DECISION_VIEW, P::HANDOVER_PREPARE,
            P::HANDOVER_COMPLETION_BASIS_RECORD, P::HANDOVER_RELEASE];
    }

    private function fixture(): array
    {
        $pdo = $this->raceSession();
        $prefix = 'hr'.bin2hex(random_bytes(3));
        $r = $this->raceCompany($pdo, $prefix);
        $actor = $this->raceUser($pdo, $r['company'], $prefix, 'actor');
        $other = $this->raceUser($pdo, $r['company'], $prefix, 'other');
        foreach ([$actor, $other] as $who) {
            foreach ($this->handoverDuties() as $duty) {
                $this->raceGrant($pdo, $r['company'], $who, $duty);
            }
        }
        $pdo->exec("update companies set timezone='Asia/Karachi' where id={$r['company']}");
        $pdo->exec('drop table if exists zz_fake_receipts');
        $pdo->exec('create table zz_fake_receipts (id bigserial primary key, company_id bigint not null, release_event_id bigint not null unique, job_id bigint, job_code text not null)');
        $this->raceBindCompany($r['company']);
        $this->activateHandover();
        $this->app->instance(QuotationTaxProvider::class, new FakeQuotationTaxProvider);
        $this->app->instance(QuotationControlsProvider::class, new FakeQuotationControlsProvider);
        $receiver = new FakeReleasedServiceReceiver;
        $receiver->durable = true;
        $this->app->instance(ReleasedServiceReceiver::class, $receiver);
        $this->registerSourceFamilies();
        $data = $this->raceCommitted(function () use ($actor): array {
            $user = User::findOrFail($actor);
            $party = (int) DB::table('parties')->where('company_id', (int) DB::table('users')->where('id', $actor)->value('company_id'))->value('id');
            $data = $this->serviceAcceptance($user, $party);
            $token = app(PreviewHandover::class)($user, $data['acceptance'], $data['inputs'])['preview_token'];
            app(PrepareHandoverPlan::class)($user, 'prepare', $data['acceptance'], $data['inputs'], $token);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $basis = $this->recordScopedBasis($user, 'basis', $data['acceptance'], $data['case']);
            $stage = (int) DB::table('handover_stages')->value('id');
            $preview = app(PreviewServiceRelease::class)($user, $stage);

            return $data + ['stage' => $stage, 'basis' => $basis['basis_id'], 'fingerprint' => $preview['fingerprint'], 'ready' => $preview['ready']];
        });

        return $data + compact('pdo', 'r', 'actor', 'other');
    }

    private function release(array $f, string $key, string $who = 'actor'): \Closure
    {
        return fn () => app(ReleaseHandoverComponent::class)(User::findOrFail($f[$who]), $key, $f['stage'], $f['basis'], $f['fingerprint']);
    }

    private function gate(array $f): array
    {
        return ['select company_id from company_access_generations order by company_id for update', "select company_id from sales_authority_heads where company_id={$f['r']['company']} for update"];
    }

    private function rows(array $f, string $table): int
    {
        return (int) $f['pdo']->query("select count(*) from {$table} where company_id={$f['r']['company']}")->fetchColumn();
    }

    private function onHolder(PDO $holder, callable $work): void
    {
        $original = config('database.default');
        config(['database.connections.race_holder' => config('database.connections.testing'), 'database.default' => 'race_holder']);
        DB::connection()->setPdo($holder);
        (new \ReflectionProperty(DB::connection(), 'transactions'))->setValue(DB::connection(), 1);
        try {
            $work();
        } finally {
            config(['database.default' => $original]);
            DB::purge('race_holder');
        }
    }

    private function cleanup(array $f): void
    {
        $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        $f['pdo']->exec('drop table if exists zz_fake_receipts');
    }

    public function test_control_a_release_after_an_unchanged_gate_creates_one_release_and_one_link(): void
    {
        $f = $this->fixture();
        try {
            $this->assertTrue($f['ready']);
            $o = $this->raceAction($f['pdo'], $this->gate($f), $this->release($f, 'only'), fn () => null);
            $this->assertTrue($o['success'], (string) $o['message']);
            $this->assertSame(1, $this->rows($f, 'handover_release_events'));
            $this->assertSame(1, $this->rows($f, 'zz_fake_receipts'));
        } finally {
            $this->cleanup($f);
        }
    }

    public function test_two_simultaneous_releases_by_a_fresh_key_or_a_second_actor_create_exactly_one_release_and_one_link(): void
    {
        foreach (['actor', 'other'] as $second) {
            $f = $this->fixture();
            try {
                $o = $this->raceAction($f['pdo'], $this->gate($f), $this->release($f, 'second', $second), fn (PDO $h) => $this->onHolder($h, $this->release($f, 'first')));
                $this->assertTrue($o['success'], (string) $o['message']);
                $this->assertSame(1, $this->rows($f, 'handover_release_events'), 'one release for the stage and purpose');
                $this->assertSame(1, $this->rows($f, 'zz_fake_receipts'), 'one Job link, one number');
                $this->assertSame(0, $this->rows($f, 'customer_acceptances') - 1, 'the release consumed no second acceptance');
            } finally {
                $this->cleanup($f);
            }
        }
    }

    public function test_revocation_or_suspension_while_waiting_refuses_before_any_write(): void
    {
        foreach (['revoke', 'suspend'] as $change) {
            $f = $this->fixture();
            try {
                $o = $this->raceAction($f['pdo'], $this->gate($f), $this->release($f, 'late'), function (PDO $h) use ($f, $change): void {
                    if ($change === 'revoke') {
                        $this->raceRevoke($h, $f['actor'], P::HANDOVER_RELEASE);
                    } else {
                        $h->exec("update users set status='SUSPENDED' where id={$f['actor']}");
                    }
                });
                $this->assertFalse($o['success']);
                $this->assertStringContainsString('not permitted', (string) $o['message']);
                $this->assertSame(0, $this->rows($f, 'handover_release_events'));
                $this->assertSame(0, $this->rows($f, 'zz_fake_receipts'));
            } finally {
                $this->cleanup($f);
            }
        }
    }
}
