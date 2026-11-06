<?php

namespace Tests\Feature\Modules\Approvals;

use App\Models\User;
use App\Modules\Approvals\Models\ApprovalAuthority;
use App\Modules\Approvals\Models\ApprovalDelegation;
use App\Modules\Numbering\Services\CompanyClock;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Authority and delegation dates are business dates (ADR 0070): they run on the company's local calendar day, read through the
 * company clock, not on the application's UTC day. Asia/Karachi is UTC+5; America/Los_Angeles is UTC-7 in summer.
 */
class ApprovalBusinessDateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        Permission::findOrCreate('approvals.authority.manage');
        Permission::findOrCreate('approvals.delegation.manage');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function companyZone(string $zone): void
    {
        DB::table('companies')->where('id', app(CompanyContext::class)->id())->update(['timezone' => $zone]);
    }

    private function at(string $utc): void
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
    }

    private function authority(?string $from, ?string $to): ApprovalAuthority
    {
        $user = User::factory()->create();

        return ApprovalAuthority::query()->create(['user_id' => $user->id, 'subject_type' => 'TEST_DOCUMENT', 'unlimited' => true, 'valid_from' => $from, 'valid_to' => $to, 'reason' => 't', 'changed_by' => $user->id]);
    }

    private function delegation(string $from, string $to): ApprovalDelegation
    {
        [$a, $b] = [User::factory()->create(), User::factory()->create()];

        return ApprovalDelegation::query()->create(['delegator_id' => $a->id, 'delegate_id' => $b->id, 'valid_from' => $from, 'valid_to' => $to, 'reason' => 't', 'created_by' => $a->id]);
    }

    /** @return array{0: bool, 1: bool, 2: bool, 3: bool} scope and resource view of an authority and a delegation */
    private function inForce(ApprovalAuthority $authority, ApprovalDelegation $delegation): array
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(['approvals.authority.manage', 'approvals.delegation.manage']);
        $this->actingAs($viewer);

        return [
            ApprovalAuthority::query()->inForce()->whereKey($authority->id)->exists(),
            ApprovalDelegation::query()->inForce()->whereKey($delegation->id)->exists(),
            (bool) $this->getJson('/api/v1/approval-authorities')->assertOk()->json('data.0.in_force'),
            (bool) $this->getJson('/api/v1/approval-delegations')->assertOk()->json('data.0.in_force'),
        ];
    }

    public function test_the_company_clock_reports_the_local_business_date(): void
    {
        $this->companyZone('Asia/Karachi');
        $this->at('2026-06-10 19:30:00');
        $this->assertSame('2026-06-11', app(CompanyClock::class)->today()->toDateString());
        $this->at('2026-06-10 18:59:00');
        $this->assertSame('2026-06-10', app(CompanyClock::class)->today()->toDateString());
    }

    public function test_a_date_limited_grant_starts_on_the_local_day_in_a_positive_offset_zone(): void
    {
        $this->companyZone('Asia/Karachi');
        $authority = $this->authority('2026-06-11', '2026-06-11');
        $delegation = $this->delegation('2026-06-11', '2026-06-11');

        // 2026-06-10 18:59 UTC is still the 10th in Karachi (23:59): not yet.
        $this->at('2026-06-10 18:59:00');
        $this->assertSame([false, false, false, false], $this->inForce($authority, $delegation));
        // 19:00 UTC is Karachi midnight: the 11th has begun, though UTC still says the 10th.
        $this->at('2026-06-10 19:00:00');
        $this->assertSame([true, true, true, true], $this->inForce($authority, $delegation));
        // Karachi 23:59 on the 11th (18:59 UTC) is the last minute; the next minute is the 12th.
        $this->at('2026-06-11 18:59:00');
        $this->assertSame([true, true, true, true], $this->inForce($authority, $delegation));
        $this->at('2026-06-11 19:00:00');
        $this->assertSame([false, false, false, false], $this->inForce($authority, $delegation));
    }

    public function test_a_date_limited_grant_ends_on_the_local_day_in_a_negative_offset_zone(): void
    {
        $this->companyZone('America/Los_Angeles');
        $authority = $this->authority('2026-06-11', '2026-06-11');
        $delegation = $this->delegation('2026-06-11', '2026-06-11');

        // 2026-06-11 06:59 UTC is 23:59 on the 10th in Los Angeles (UTC-7): not yet, though UTC says the 11th.
        $this->at('2026-06-11 06:59:00');
        $this->assertSame([false, false, false, false], $this->inForce($authority, $delegation));
        $this->at('2026-06-11 07:00:00');
        $this->assertSame([true, true, true, true], $this->inForce($authority, $delegation));
        // 2026-06-12 05:00 UTC is still the 11th in Los Angeles, the grant's last day.
        $this->at('2026-06-12 05:00:00');
        $this->assertSame([true, true, true, true], $this->inForce($authority, $delegation));
        $this->at('2026-06-12 07:00:00');
        $this->assertSame([false, false, false, false], $this->inForce($authority, $delegation));
    }

    public function test_a_utc_company_keeps_the_utc_day(): void
    {
        $authority = $this->authority('2026-06-11', '2026-06-11');
        $delegation = $this->delegation('2026-06-11', '2026-06-11');
        $this->at('2026-06-10 23:59:00');
        $this->assertSame([false, false, false, false], $this->inForce($authority, $delegation));
        $this->at('2026-06-11 00:00:00');
        $this->assertSame([true, true, true, true], $this->inForce($authority, $delegation));
    }
}
