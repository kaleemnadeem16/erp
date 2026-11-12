<?php

namespace Tests\Feature\Modules\Sales;

use App\Modules\Sales\Actions\AssessAcceptanceReadiness;
use App\Modules\Sales\Actions\PrepareHandoverPlan;
use App\Modules\Sales\Actions\PreviewHandover;
use App\Modules\Sales\Actions\ReadHandoverPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Modules\Sales\Concerns\BuildsHandoverFixture;
use Tests\Feature\Modules\Sales\Concerns\BuildsIssuableQuotations;
use Tests\TestCase;

class HandoverReadinessTest extends TestCase
{
    use BuildsHandoverFixture;
    use BuildsIssuableQuotations;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIssuing();
        $this->activateHandover();
        foreach ($this->handoverDuties() as $duty) {
            Permission::findOrCreate($duty, 'web');
            $this->checker->givePermissionTo($duty);
        }
    }

    public function test_missing_service_facts_name_their_owner_and_remote_work_needs_no_fake_warehouse_or_measure(): void
    {
        $f = $this->serviceAcceptance($this->checker, $this->customer->id);
        $pending = app(AssessAcceptanceReadiness::class)($this->checker, $f['acceptance']);
        $this->assertFalse($pending['preparation_ready']);
        $this->assertContains('SERVICE_CONTACT_REQUIRED', $pending['missing']);
        $this->assertContains('SERVICE_LOCATION_REQUIRED', $pending['missing']);
        $this->assertContains('Parties', array_column($pending['next_actions'], 'owner'));
        $readyForPreparation = app(AssessAcceptanceReadiness::class)($this->checker, $f['acceptance'], 'SERVICE_JOB', $f['inputs']);
        $this->assertTrue($readyForPreparation['preparation_ready']);
        $this->assertSame('NOT_READY', $readyForPreparation['status']);
        $this->assertNotContains('SERVICE_COST_CERTAINTY_UNKNOWN', $readyForPreparation['missing'], 'cost is conditional on a mandatory control; none is delivered');
        $this->assertSame('UNASSESSED', $readyForPreparation['cost']['status']);
        foreach (['COMPLETION_CONDITIONS_NOT_DELIVERED', 'INVENTORY_APPLICABILITY_UNVERIFIED', 'PURCHASING_APPLICABILITY_UNVERIFIED', 'SERVICE_JOB_ADAPTER_NOT_DELIVERED'] as $code) {
            $this->assertContains($code, $readyForPreparation['missing']);
        }
    }

    public function test_goods_remain_pending_without_a_fake_job_target(): void
    {
        $f = $this->serviceAcceptance($this->checker, $this->customer->id, 'DIRECT_ITEM');
        $pending = app(AssessAcceptanceReadiness::class)($this->checker, $f['acceptance'], 'GOODS_ORDER');
        $this->assertSame('NOT_READY', $pending['status']);
        $this->assertFalse($pending['preparation_ready']);
        $this->assertSame(['HANDOVER_PATH_UNSUPPORTED'], $pending['missing']);
    }

    public function test_plan_reads_use_frozen_context_and_do_not_return_private_review_or_commercial_fields(): void
    {
        $f = $this->serviceAcceptance($this->checker, $this->customer->id);
        $p = app(PreviewHandover::class)($this->checker, $f['acceptance'], $f['inputs']);
        $out = app(PrepareHandoverPlan::class)($this->checker, 'prepare', $f['acceptance'], $f['inputs'], $p['preview_token']);
        DB::table('party_contacts')->where('id', $f['contact'])->update(['name' => 'Changed live name']);
        $plan = app(ReadHandoverPlan::class)($this->checker, $out['plan_id']);
        $this->assertSame('Synthetic delivery contact', $plan['operational']['contact']['name']);
        $this->assertFalse($plan['release_performed']);
        $this->assertArrayNotHasKey('preview_token', $plan);
        $this->assertArrayNotHasKey('intent_digest', $plan);
        $this->assertArrayNotHasKey('unit_price', $plan['operational']);
        $this->assertNull($plan['operational']['quantity']);
    }
}
