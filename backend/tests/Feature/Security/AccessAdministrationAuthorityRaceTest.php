<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Modules\Administration\Actions\SaveRole;
use App\Modules\Administration\Actions\SyncMemberAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use Tests\Feature\Security\Concerns\TwoSessionRace;
use Tests\TestCase;

/**
 * Issue #94 (review A3): ordinary access administration decides what the actor may grant INSIDE its protected write transaction. The test holds the company access
 * barrier so the Action has already passed its early checks and is waiting; the actor's authority is then withdrawn and committed, and the barrier released. The write
 * must be refused and leave nothing behind. The control cases prove the same staging lets an unchanged actor succeed.
 */
class AccessAdministrationAuthorityRaceTest extends TestCase
{
    use RefreshDatabase;
    use TwoSessionRace;

    private const ASSIGN = 'administration.access.assign';

    private const ROLE_MANAGE = 'administration.role.manage';

    private const GRANTED = 'purchasing.purchase-order.approve';

    /** @return array{pdo: PDO, r: array<string,int>, actor: int, member: int} */
    private function fixture(string $managerDuty): array
    {
        $pdo = $this->raceSession();
        $prefix = 'aa'.bin2hex(random_bytes(3));
        $r = $this->raceCompany($pdo, $prefix);
        $actor = $this->raceUser($pdo, $r['company'], $prefix, 'actor');
        $member = $this->raceUser($pdo, $r['company'], $prefix, 'member');
        $this->raceGrant($pdo, $r['company'], $actor, $managerDuty);
        $this->raceGrant($pdo, $r['company'], $actor, self::GRANTED);
        $pdo->exec("insert into company_access_generations (company_id) values ({$r['company']}) on conflict do nothing");
        $this->raceBindCompany($r['company']);

        return compact('pdo', 'r', 'actor', 'member');
    }

    private function gate(array $f): string
    {
        // Every company barrier in ascending order, exactly as the writers take them, so the holder's own later writes cannot deadlock with the waiting Action.
        return 'select company_id from company_access_generations order by company_id for update';
    }

    private function sync(array $f): \Closure
    {
        return fn () => app(SyncMemberAccess::class)(User::query()->findOrFail($f['member']), ['role_ids' => [], 'direct_permissions' => [self::GRANTED], 'reason' => 'race'], User::query()->findOrFail($f['actor']));
    }

    private function role(array $f): \Closure
    {
        return fn () => app(SaveRole::class)(null, ['name' => 'Raced role', 'permissions' => [self::GRANTED], 'reason' => 'race'], User::query()->findOrFail($f['actor']));
    }

    private function memberPermissions(array $f): int
    {
        return (int) $f['pdo']->query("select count(*) from model_has_permissions where model_id = {$f['member']}")->fetchColumn();
    }

    private function roles(array $f): int
    {
        return (int) $f['pdo']->query("select count(*) from roles where team_id = {$f['r']['company']}")->fetchColumn();
    }

    public function test_control_member_access_is_granted_when_nothing_changes(): void
    {
        $f = $this->fixture(self::ASSIGN);
        try {
            $o = $this->raceAction($f['pdo'], $this->gate($f), $this->sync($f), fn () => null);
            $this->assertTrue($o['success'], (string) $o['message']);
            $this->assertSame(1, $this->memberPermissions($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_member_access_is_refused_when_the_actor_loses_the_assign_duty_meanwhile(): void
    {
        $f = $this->fixture(self::ASSIGN);
        try {
            $o = $this->raceAction($f['pdo'], $this->gate($f), $this->sync($f), fn ($h) => $this->raceRevoke($h, $f['actor'], self::ASSIGN));
            $this->assertFalse($o['success']);
            $this->assertSame(0, $this->memberPermissions($f), 'nothing is granted on the strength of a revoked duty');
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_member_access_is_refused_when_the_actor_loses_the_duty_being_granted_meanwhile(): void
    {
        $f = $this->fixture(self::ASSIGN);
        try {
            $o = $this->raceAction($f['pdo'], $this->gate($f), $this->sync($f), fn ($h) => $this->raceRevoke($h, $f['actor'], self::GRANTED));
            $this->assertFalse($o['success']);
            $this->assertSame(0, $this->memberPermissions($f), 'a grantor cannot hand out a duty it no longer holds');
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_member_access_is_refused_when_the_actor_is_suspended_meanwhile(): void
    {
        $f = $this->fixture(self::ASSIGN);
        try {
            $o = $this->raceAction($f['pdo'], $this->gate($f), $this->sync($f), fn ($h) => $h->exec("update users set status = 'SUSPENDED' where id = {$f['actor']}"));
            $this->assertFalse($o['success']);
            $this->assertSame(0, $this->memberPermissions($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_control_a_role_is_saved_when_nothing_changes(): void
    {
        $f = $this->fixture(self::ROLE_MANAGE);
        try {
            $o = $this->raceAction($f['pdo'], $this->gate($f), $this->role($f), fn () => null);
            $this->assertTrue($o['success'], (string) $o['message']);
            $this->assertSame(1, $this->roles($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_a_role_is_refused_when_the_actor_loses_the_manage_duty_meanwhile(): void
    {
        $f = $this->fixture(self::ROLE_MANAGE);
        try {
            $o = $this->raceAction($f['pdo'], $this->gate($f), $this->role($f), fn ($h) => $this->raceRevoke($h, $f['actor'], self::ROLE_MANAGE));
            $this->assertFalse($o['success']);
            $this->assertSame(0, $this->roles($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }

    public function test_a_role_is_refused_when_the_actor_loses_a_duty_it_puts_in_the_role_meanwhile(): void
    {
        $f = $this->fixture(self::ROLE_MANAGE);
        try {
            $o = $this->raceAction($f['pdo'], $this->gate($f), $this->role($f), fn ($h) => $this->raceRevoke($h, $f['actor'], self::GRANTED));
            $this->assertFalse($o['success']);
            $this->assertSame(0, $this->roles($f));
        } finally {
            $this->purgeRaceCompany($f['pdo'], $f['r']['company']);
        }
    }
}
