<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Approvals\Actions\DecideApproval;
use App\Modules\Approvals\Models\ApprovalAuthority;
use App\Modules\Approvals\Models\ApprovalDelegation;
use App\Modules\Approvals\Models\ApprovalPolicy;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Support\ApprovalDecisionKind;
use App\Modules\Approvals\Support\ApprovalInbox;
use App\Modules\Estimating\Actions\PreviewMaterialVariance;
use App\Modules\Estimating\Models\MaterialVariancePolicy;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Actions\IssuePurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

/**
 * ApprovalsDemoSeeder claims a company configured with amount-banded policies, three personas and an order in every
 * approval state. Each claim is proved by driving the real engine, not by reading the rows back.
 */
final class ApprovalsDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('demo:seed')->assertSuccessful();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    private function request(string $marker): ApprovalRequest
    {
        $order = PurchaseOrder::query()->where('notes', 'like', "%DEMO_APPROVAL_{$marker})%")->sole();

        return ApprovalRequest::query()->where('subject_type', 'PURCHASE_ORDER')->where('subject_id', $order->id)->sole();
    }

    public function test_every_approval_state_is_represented_and_the_bands_route_by_amount(): void
    {
        $this->assertSame(
            ['A' => 'PENDING', 'B' => 'PENDING', 'C' => 'APPROVED', 'D' => 'REJECTED', 'E' => 'WITHDRAWN'],
            collect(['A', 'B', 'C', 'D', 'E'])->mapWithKeys(fn (string $m): array => [$m => $this->request($m)->status->value])->all(),
        );
        // Under 500,000 one manager step; from 500,000 a manager then two Finance approvals.
        $this->assertSame('po-over-100k', $this->request('A')->policy->code);
        $this->assertSame(1, $this->request('A')->steps()->count());
        $this->assertSame('po-over-500k', $this->request('B')->policy->code);
        $this->assertSame(2, $this->request('B')->steps()->count());
        $this->assertSame(1, $this->request('B')->steps()->where('stage', 2)->sole()->decisions()->count());
        $this->assertSame('PURCHASE_ORDER', $this->request('C')->subject_type);
        $this->assertSame('ISSUED', PurchaseOrder::query()->findOrFail($this->request('C')->subject_id)->status->value);
    }

    public function test_the_manager_cannot_approve_beyond_their_limit_and_finance_can(): void
    {
        $manager = $this->user('demo-manager@erp.x1techs.com');
        $inbox = app(ApprovalInbox::class);

        $mine = $inbox->forUser($manager)->pluck('id')->all();
        $this->assertContains($this->request('A')->id, $mine, '250,000 is within the 300,000 limit');
        $this->assertNotContains($this->request('B')->id, $mine, 'the 650,000 order is beyond it');

        // The demo login, who has no ceiling, can finish the pending steps live.
        $admin = $this->user(config('demo.user.email'));
        $approved = app(DecideApproval::class)($this->request('A'), $admin, ApprovalDecisionKind::Approved, 'Approved live.');
        $this->assertSame('APPROVED', $approved->status->value);
        $order = PurchaseOrder::query()->findOrFail($approved->subject_id);
        $this->assertSame('ISSUED', app(IssuePurchaseOrder::class)($order, $admin)->status->value);
    }

    public function test_a_delegate_acts_as_the_manager_but_cannot_approve_what_they_submitted(): void
    {
        $manager = $this->user('demo-manager@erp.x1techs.com');
        $buyer = $this->user('demo-buyer@erp.x1techs.com');
        $this->assertTrue(ApprovalDelegation::query()->inForce()->where('delegator_id', $manager->id)->where('delegate_id', $buyer->id)->exists());

        // The Buyer holds no approval capability of their own; the delegation would give the manager's — but the Buyer is the
        // submitter of every demo order, so the step's maker/checker rule still refuses them.
        try {
            app(DecideApproval::class)($this->request('A'), $buyer, ApprovalDecisionKind::Approved);
            $this->fail('A submitter must not approve their own submission, even as a delegate.');
        } catch (DomainInvariantException $e) {
            $this->assertStringContainsString('cannot approve this step', $e->getMessage());
        }
    }

    public function test_the_pigment_variance_rule_now_routes_a_large_plan_to_approval(): void
    {
        $paint = Item::query()->where('code', 'demo-pig-blue')->firstOrFail();
        $leaf = ['item' => ['id' => $paint->id, 'code' => $paint->code, 'name' => $paint->name], 'standard_quantity' => '10.0000', 'uom' => ['id' => $paint->stock_uom_id, 'code' => 'kg', 'name' => 'Kilogram']];

        [$data] = app(PreviewMaterialVariance::class)([$leaf], [['item_id' => $paint->id, 'planned_quantity' => '25', 'uom_id' => $paint->stock_uom_id]], PermissionedActor::grant(User::factory()->create(), ['estimating.material_preview.run']));
        $item = $data['items'][0];

        $this->assertSame('APPROVAL_REQUIRED', $item['effect']);
        $this->assertSame('Variance sign-off', $item['approval']['policy']['name']);
        $this->assertFalse($item['can_adopt']);
    }

    public function test_using_up_the_waiting_order_makes_reseeding_submit_a_fresh_one_and_only_one(): void
    {
        $waiting = fn () => ApprovalRequest::query()->where('subject_type', 'PURCHASE_ORDER')->where('status', 'PENDING')->get()
            ->filter(fn (ApprovalRequest $r) => str_contains((string) PurchaseOrder::query()->find($r->subject_id)?->notes, 'DEMO_APPROVAL_A'));
        $this->assertCount(1, $waiting());

        // The demo's point: someone approves it live. That uses it up.
        app(DecideApproval::class)($this->request('A'), $this->user('demo-manager@erp.x1techs.com'), ApprovalDecisionKind::Approved, 'Approved live.');
        $this->assertCount(0, $waiting());

        $this->artisan('demo:seed')->assertSuccessful();
        $this->assertCount(1, $waiting());
        $this->assertSame('250000.0000', number_format((float) PurchaseOrder::query()->find($waiting()->first()->subject_id)->lines()->first()->unit_price * 1000, 4, '.', ''));

        $this->artisan('demo:seed')->assertSuccessful();
        $this->assertCount(1, $waiting(), 'a second re-seed must not add another while one is waiting');
    }

    public function test_lapsed_authority_and_delegation_are_renewed_without_replacing_the_live_request(): void
    {
        $manager = $this->user('demo-manager@erp.x1techs.com');
        $request = $this->request('A');
        ApprovalAuthority::query()->where('user_id', $manager->id)->update(['valid_to' => today()->subDay()]);
        ApprovalDelegation::query()->where('delegator_id', $manager->id)->update(['valid_from' => today()->subDays(8), 'valid_to' => today()->subDay()]);
        $this->assertNull(app(ApprovalInbox::class)->actionableStep($manager, $request));
        $this->artisan('demo:seed')->assertSuccessful();
        $this->assertSame($request->id, $this->request('A')->id);
        $this->assertNotNull(app(ApprovalInbox::class)->actionableStep($manager->fresh(), $request->fresh()));
        $this->assertSame(1, ApprovalAuthority::query()->inForce()->where('user_id', $manager->id)->count());
        $this->assertSame(1, ApprovalDelegation::query()->inForce()->where('delegator_id', $manager->id)->count());
        $counts = [ApprovalAuthority::query()->count(), ApprovalDelegation::query()->count()];
        $this->artisan('demo:seed')->assertSuccessful();
        $this->assertSame($counts, [ApprovalAuthority::query()->count(), ApprovalDelegation::query()->count()]);
    }

    public function test_technical_demo_persona_reviews_without_prices_then_commercial_approval_is_separate(): void
    {
        $technical = $this->user('demo-technical@erp.x1techs.com');
        $this->assertSame(['approvals.document.technical-review'], $technical->getAllPermissions()->pluck('name')->all());
        $request = $this->request('F');
        $response = $this->actingAs($technical)->getJson('/api/v1/approval-requests/'.$request->id)->assertOk()
            ->assertJsonPath('data.document_review.profile', 'OPERATIONAL')->assertJsonPath('data.amount_base', null);
        $this->assertStringNotContainsString('Unit price', $response->getContent());
        app(DecideApproval::class)($request, $technical, ApprovalDecisionKind::Approved);
        $this->assertNull(app(ApprovalInbox::class)->actionableStep($technical, $request->fresh()));
        $manager = $this->user('demo-manager@erp.x1techs.com');
        $this->actingAs($manager)->getJson('/api/v1/approval-requests/'.$request->id)->assertOk()
            ->assertJsonPath('data.document_review.profile', 'COMMERCIAL');
        app(DecideApproval::class)($request->fresh(), $manager, ApprovalDecisionKind::Approved);
        $this->artisan('demo:seed')->assertSuccessful();
        $waiting = app(ApprovalInbox::class)->forUser($technical->fresh());
        $this->assertCount(1, $waiting);
        $this->assertNotSame($request->id, $waiting->first()->id);
        $this->artisan('demo:seed')->assertSuccessful();
        $this->assertCount(1, app(ApprovalInbox::class)->forUser($technical->fresh()));
    }

    public function test_reseeding_changes_nothing(): void
    {
        $counts = fn (): array => [ApprovalRequest::query()->count(), PurchaseOrder::query()->count(), ApprovalDelegation::query()->count(), ApprovalPolicy::query()->count(), MaterialVariancePolicy::query()->count()];
        $before = $counts();

        $this->artisan('demo:seed')->assertSuccessful();

        $this->assertSame($before, $counts());
    }
}
