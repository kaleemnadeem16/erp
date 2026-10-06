<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\Demo\ApprovalAuditorsDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ApprovalAuditorsDemoSeeder claims two administrator-built roles that differ only in the auditor capabilities. Each claim is proved
 * through the real API, on the rejected order the approvals demo already holds (its reason mentions a price, which is exactly what a
 * reason can leak), not by reading rows back.
 */
final class ApprovalAuditorsDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private const PRICE_REASON = 'Price is well above the last three orders; renegotiate first.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('demo:seed')->assertSuccessful();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
    }

    private function persona(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function rejectedOrder(User $viewer): array
    {
        $rows = $this->actingAs($viewer)->getJson('/api/v1/approval-requests?per_page=100')->assertOk()->json('data');

        return collect($rows)->firstWhere('status', 'REJECTED') ?? $this->fail('The demo holds no rejected approval.');
    }

    public function test_the_roles_hold_exactly_the_capabilities_their_names_promise(): void
    {
        $auditor = Role::query()->where('team_id', 1)->where('name', ApprovalAuditorsDemoSeeder::AUDITOR_ROLE)->sole();
        $reader = Role::query()->where('team_id', 1)->where('name', ApprovalAuditorsDemoSeeder::READER_ROLE)->sole();
        $this->assertEqualsCanonicalizing(['approvals.history.view', 'approvals.authority.view', 'approvals.reason.view'], $auditor->permissions()->pluck('name')->all());
        $this->assertSame(['approvals.history.view'], $reader->permissions()->pluck('name')->all());
        $this->assertSame([ApprovalAuditorsDemoSeeder::AUDITOR_ROLE], $this->persona(ApprovalAuditorsDemoSeeder::AUDITOR_EMAIL)->getRoleNames()->all());
        $this->assertSame([ApprovalAuditorsDemoSeeder::READER_ROLE], $this->persona(ApprovalAuditorsDemoSeeder::READER_EMAIL)->getRoleNames()->all());
    }

    public function test_the_auditor_reads_limits_and_reasons_and_the_history_reader_reads_neither(): void
    {
        $auditor = $this->persona(ApprovalAuditorsDemoSeeder::AUDITOR_EMAIL);
        $reader = $this->persona(ApprovalAuditorsDemoSeeder::READER_EMAIL);

        $this->actingAs($auditor)->getJson('/api/v1/approval-authorities')->assertOk()->assertJsonStructure(['data' => [['user_id', 'ceiling_base_amount']]]);
        $this->actingAs($reader)->getJson('/api/v1/approval-authorities')->assertForbidden();

        $seen = $this->rejectedOrder($auditor);
        $this->assertSame(self::PRICE_REASON, $seen['steps'][0]['decisions'][0]['reason']);
        $this->assertSame(self::PRICE_REASON, $seen['closing_reason']);

        $blind = $this->actingAs($reader)->getJson('/api/v1/approval-requests?per_page=100')->assertOk();
        $this->assertStringNotContainsString('renegotiate', $blind->getContent());
        $row = collect($blind->json('data'))->firstWhere('id', $seen['id']);
        $this->assertSame('REJECTED', $row['status']);
        $this->assertFalse($row['closing_reason_visible']);
        $this->assertSame($seen['steps'][0]['decisions'][0]['decided_by'], $row['steps'][0]['decisions'][0]['decided_by'], 'who decided is still shown');

        // Neither can change anything.
        $this->actingAs($auditor)->postJson('/api/v1/approval-authorities', ['user_id' => $reader->id, 'subject_type' => 'PURCHASE_ORDER', 'unlimited' => true, 'reason' => 'x'])->assertForbidden();
    }

    public function test_reseeding_neither_duplicates_a_role_nor_changes_an_assignment(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);

        foreach ([ApprovalAuditorsDemoSeeder::AUDITOR_ROLE, ApprovalAuditorsDemoSeeder::READER_ROLE] as $name) {
            $this->assertSame(1, Role::query()->where('team_id', 1)->where('name', $name)->count());
        }
        $this->assertSame([ApprovalAuditorsDemoSeeder::READER_ROLE], $this->persona(ApprovalAuditorsDemoSeeder::READER_EMAIL)->getRoleNames()->all());
    }
}
