<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Modules\Sales\Actions\AppendCustomerDecisionEvidence;
use App\Modules\Sales\Actions\OpenCustomerDecisionCase;
use App\Modules\Sales\Support\Authority\DutyStatus;
use App\Modules\Sales\Support\Authority\SalesDuty;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as P;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use Tests\Feature\Security\Concerns\TwoSessionRace;
use Tests\TestCase;

/**
 * Customer acceptance slice 1 (record what the customer sent): the two intake Actions decide their actor INSIDE the transaction, from the stored person, after the company access
 * barrier (DecisionActor), and the case head is serialised by the case lock. Real two-session races on PostgreSQL, staged like DecisionTimeAuthorityDraftRaceTest: the test holds the
 * barrier (or the case row), the Action's forked process blocks on it, the holder commits a change, and the Action then continues and commits or refuses.
 */
class CustomerDecisionAuthorityRaceTest extends TestCase
{
    use RefreshDatabase;
    use TwoSessionRace;

    /** @return array{pdo: PDO, r: array<string,int>, actor: int, party: int, case: int} */
    private function fixture(): array
    {
        $pdo = $this->raceSession();
        $prefix = 'cd'.bin2hex(random_bytes(3));
        $r = $this->raceCompany($pdo, $prefix);
        $actor = $this->raceUser($pdo, $r['company'], $prefix, 'actor');
        $this->raceGrant($pdo, $r['company'], $actor, P::CUSTOMER_DECISION_EVIDENCE_RECORD);
        $pdo->exec("insert into company_access_generations (company_id) values ({$r['company']}) on conflict do nothing");
        $this->raceBindCompany($r['company']);
        // The duty is STAGED in the shipped manifest; the race needs it live, exactly as an activated deployment would.
        $this->app->instance(SalesDutyManifest::class, new SalesDutyManifest(array_map(fn (SalesDuty $d): SalesDuty => $d->name === P::CUSTOMER_DECISION_EVIDENCE_RECORD
            ? new SalesDuty($d->name, $d->class, DutyStatus::Active, $d->operation, $d->projection, $d->deliveryGate, $d->scope) : $d, SalesDutyManifest::shipped()->all())));
        $party = (int) $pdo->query("select id from parties where company_id = {$r['company']} limit 1")->fetchColumn();
        $case = (int) $pdo->query("insert into customer_decision_cases (company_id, party_id, intake_identity, generation, recorded_by, recorded_at) values ({$r['company']}, {$party}, 'PO-RACE', 0, {$actor}, now()) returning id")->fetchColumn();

        return compact('pdo', 'r', 'actor', 'party', 'case');
    }

    /** @return list<string> */
    private function barrier(): array
    {
        return ['select company_id from company_access_generations order by company_id for update'];
    }

    private function scalar(array $f, string $sql): string
    {
        return (string) $f['pdo']->query($sql)->fetchColumn();
    }

    private function actorUser(array $f): User
    {
        return User::query()->findOrFail($f['actor']);
    }

    private function open(array $f): \Closure
    {
        return fn () => app(OpenCustomerDecisionCase::class)($this->actorUser($f), 'race-open', $f['party'], 'PO-NEW');
    }

    private function append(array $f, int $generation = 0): \Closure
    {
        return fn () => app(AppendCustomerDecisionEvidence::class)($this->actorUser($f), 'race-append', $f['case'], $generation, [
            'source_kind' => 'EMAIL', 'authority_verification_basis' => 'UNVERIFIED', 'occurrence_precision' => 'UNKNOWN', 'factual_description' => 'A race observation.',
        ]);
    }

    private function counts(array $f): string
    {
        return $this->scalar($f, "select (select count(*) from customer_decision_cases where company_id = {$f['r']['company']}) || '/' || (select count(*) from customer_decision_evidence where company_id = {$f['r']['company']})"
            ." || '/' || (select count(*) from sales_customer_decision_receipts where company_id = {$f['r']['company']})");
    }

    private function refused(array $f, \Closure $action, \Closure $mutate): void
    {
        $o = $this->raceAction($f['pdo'], $this->barrier(), $action, $mutate);
        $this->assertFalse($o['success']);
        $this->assertStringContainsString('not permitted', (string) $o['message']);
    }

    public function test_control_an_unchanged_actor_opens_a_case_and_appends_an_observation(): void
    {
        $f = $this->fixture();
        try {
            $this->assertTrue($this->raceAction($f['pdo'], $this->barrier(), $this->open($f), fn () => null)['success']);
            $this->assertSame('2/0/1', $this->counts($f));
            $this->assertTrue($this->raceAction($f['pdo'], $this->barrier(), $this->append($f), fn () => null)['success']);
            $this->assertSame('2/1/2', $this->counts($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_a_case_is_not_opened_after_the_duty_is_revoked_or_the_actor_suspended_meanwhile(): void
    {
        foreach (['revoked' => fn (array $f) => fn ($h) => $this->raceRevoke($h, $f['actor'], P::CUSTOMER_DECISION_EVIDENCE_RECORD),
            'suspended' => fn (array $f) => fn ($h) => $h->exec("update users set status = 'SUSPENDED' where id = {$f['actor']}")] as $why => $mutate) {
            $f = $this->fixture();
            try {
                $this->refused($f, $this->open($f), $mutate($f));
                $this->assertSame('1/0/0', $this->counts($f), "no case and no receipt after {$why}");
            } finally {
                $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
            }
        }
    }

    public function test_an_observation_is_not_appended_after_the_duty_is_revoked_or_the_actor_suspended_meanwhile(): void
    {
        foreach (['revoked' => fn (array $f) => fn ($h) => $this->raceRevoke($h, $f['actor'], P::CUSTOMER_DECISION_EVIDENCE_RECORD),
            'suspended' => fn (array $f) => fn ($h) => $h->exec("update users set status = 'SUSPENDED' where id = {$f['actor']}")] as $why => $mutate) {
            $f = $this->fixture();
            try {
                $this->refused($f, $this->append($f), $mutate($f));
                $this->assertSame('1/0/0', $this->counts($f), "no observation, no generation change and no receipt after {$why}");
                $this->assertSame('0', $this->scalar($f, "select generation from customer_decision_cases where id = {$f['case']}"));
            } finally {
                $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
            }
        }
    }

    public function test_a_second_writer_that_lost_the_case_lock_is_refused_as_stale_and_adds_nothing(): void
    {
        $f = $this->fixture();
        try {
            // The holder is the first writer: it holds the case row, the Action (expecting generation 0) waits on that lock, and the holder commits an observation and moves the head.
            $o = $this->raceAction($f['pdo'], ["select id from customer_decision_cases where id = {$f['case']} for update"], $this->append($f, 0), function (PDO $h) use ($f): void {
                $h->exec("insert into customer_decision_evidence (company_id, case_id, source_kind, authority_verification_basis, occurrence_precision, factual_description, observation_state, recorded_by, recorded_at) values ({$f['r']['company']}, {$f['case']}, 'EMAIL', 'UNVERIFIED', 'UNKNOWN', 'The first writer.', 'OBSERVED', {$f['actor']}, now())");
                $h->exec("update customer_decision_cases set generation = 1 where id = {$f['case']}");
            });
            $this->assertFalse($o['success']);
            $this->assertStringContainsString('generation', (string) $o['message']);
            $this->assertSame('1/1/0', $this->counts($f), 'only the first writer\'s observation exists and the loser left no receipt');
            $this->assertSame('1', $this->scalar($f, "select generation from customer_decision_cases where id = {$f['case']}"));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }
}
