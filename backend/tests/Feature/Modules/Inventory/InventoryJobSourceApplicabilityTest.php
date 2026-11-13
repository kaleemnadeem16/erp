<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Models\Company;
use App\Modules\Inventory\Actions\DecideRecalculationApproval;
use App\Modules\Inventory\Actions\ExecuteRecalculation;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Actions\RequestNegativeStockOverride;
use App\Modules\Inventory\Actions\RequestStockRecalculation;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Support\JobSource\InventoryJobSourceApplicability;
use App\Modules\Inventory\Support\RecalculationApprovalDecision;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Support\JobSource\FamilyAssessment;
use App\Support\JobSource\JobSourceFamilies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Modules\Inventory\Concerns\BuildsOutboundRecalculation;
use Tests\Support\PurchasingActor;
use Tests\TestCase;

class InventoryJobSourceApplicabilityTest extends TestCase
{
    use BuildsOutboundRecalculation, RefreshDatabase;

    public function test_registration_reports_no_supported_job_binding_and_foreign_company_is_unknown(): void
    {
        $assessment = collect(app(JobSourceFamilies::class)->assessAll(1))->keyBy('family')->get('INVENTORY_MATERIAL');
        $this->assertNotNull($assessment);
        $this->assertSame(FamilyAssessment::NOT_APPLICABLE, $assessment->status);
        $this->assertSame(InventoryJobSourceApplicability::CONTRACT_VERSION, $assessment->contractVersion);
        $this->assertSame([], $assessment->supportedBindings);
        foreach ([0, 2] as $company) {
            $this->assertSame(FamilyAssessment::UNKNOWN, (new InventoryJobSourceApplicability)->assess($company)->status);
        }
    }

    public function test_supported_binding_cannot_silently_keep_not_applicable(): void
    {
        $registry = new JobSourceFamilies;
        $registry->register(new InventoryJobSourceApplicability(['TEST_VALIDATED_BINDING.1']));
        $answer = collect($registry->assessAll(1))->keyBy('family')->get('INVENTORY_MATERIAL');
        $this->assertSame(FamilyAssessment::APPLICABLE, $answer->status);
        $this->assertSame(['TEST_VALIDATED_BINDING.1'], $answer->supportedBindings);
    }

    public function test_scalar_job_named_provenance_is_not_a_validated_job_binding(): void
    {
        $this->fixture(ValuationMethod::Fifo);
        $a = $this->location('a');
        $input = ['item_id' => $this->item->id, 'location_id' => $a->id, 'qty' => '5', 'uom_id' => $this->uom->id,
            'source_type' => 'SERVICE_JOB', 'source_id' => 987654, 'moved_at' => '2026-01-01', 'unit_cost' => '10',
            'base_unit_cost' => '10', 'currency_id' => Company::findOrFail(1)->base_currency_id, 'fx_rate' => '1'];
        $post = app(PostInventoryMovement::class);
        $receipt = $post($input);
        $issue = $post->issue(array_replace($input, ['qty' => '1', 'source_type' => 'JOB_MATERIAL', 'moved_at' => '2026-01-02']));
        $this->assertSame('JOB_MATERIAL', $issue->source_type);
        $this->assertSame($receipt->id, $post($input)->id);
        $this->assertSame($issue->id, $post->issue(array_replace($input, ['qty' => '1', 'source_type' => 'JOB_MATERIAL', 'moved_at' => '2026-01-02']))->id);
        $this->assertSame(2, StockMove::query()->count());
        $this->assertNoBinding();
    }

    public function test_transfer_and_receipt_correction_sources_stay_provenance(): void
    {
        $this->fixture(ValuationMethod::Fifo);
        $a = $this->location('a');
        $b = $this->location('b');
        $this->receive($a, '5', '10', '2026-01-01', 1);
        $post = app(PostInventoryMovement::class);
        $transfer = $post->transfer(['item_id' => $this->item->id, 'from_location_id' => $a->id, 'to_location_id' => $b->id,
            'qty' => '2', 'uom_id' => $this->uom->id, 'source_type' => 'SERVICE_JOB', 'source_id' => 987654, 'moved_at' => '2026-01-02']);
        $this->assertSame('SERVICE_JOB', $transfer->source_type);
        $post(['item_id' => $this->item->id, 'location_id' => $a->id, 'qty' => '3', 'uom_id' => $this->uom->id,
            'source_type' => 'SERVICE_JOB_CORRECTION', 'source_id' => 77, 'moved_at' => '2026-01-03', 'unit_cost' => '10',
            'base_unit_cost' => '10', 'currency_id' => Company::findOrFail(1)->base_currency_id, 'fx_rate' => '1']);
        $reversal = $post->reverseReceipt('SERVICE_JOB_CORRECTION', 77, 'JOB_REVERSAL', 77, '2026-01-04');
        $this->assertSame('JOB_REVERSAL', $reversal->source_type);
        $this->assertSame($reversal->id, $post->reverseReceipt('SERVICE_JOB_CORRECTION', 77, 'JOB_REVERSAL', 77, '2026-01-04')->id);
        $this->assertSame(4, StockMove::query()->count());
        $this->assertNoBinding();
    }

    public function test_backdated_execution_and_replay_preserve_provenance_without_job_attribution(): void
    {
        $this->fixture(ValuationMethod::Fifo);
        $a = $this->location('a');
        $this->receive($a, '5', '10', '2026-01-01', 1);
        $this->issue($a, '2', '2026-01-10', 2);
        $input = ['movement_kind' => 'ISSUE', 'item_id' => $this->item->id, 'location_id' => $a->id,
            'qty' => '3', 'source_type' => 'SERVICE_JOB', 'source_id' => 987654, 'moved_at' => '2026-01-05'];
        $request = app(RequestStockRecalculation::class)($input, $this->requester);
        $this->assertSame($request->id, app(RequestStockRecalculation::class)($input, $this->requester)->id);
        $version = $request->impactVersions->sole();
        app(DecideRecalculationApproval::class)($version, RecalculationApprovalDecision::Approved, 'Checked', PurchasingActor::make(['inventory.recalculation.approve']));
        $executor = PurchasingActor::make(['inventory.recalculation.execute']);
        $execution = app(ExecuteRecalculation::class)($version->fresh(), $executor);
        $move = StockMove::query()->findOrFail($execution->stock_move_id);
        $this->assertSame('SERVICE_JOB', $move->source_type);
        $this->assertSame($execution->id, app(ExecuteRecalculation::class)($version->fresh(), $executor)->id);
        $this->assertSame(3, StockMove::query()->count());
        $this->assertNoBinding();
    }

    public function test_override_intake_does_not_turn_source_provenance_into_job_allocation(): void
    {
        $this->fixture(ValuationMethod::Fifo);
        $a = $this->location('a');
        Company::findOrFail(1)->update(['negative_stock_mode' => 'AUTHORISED_OVERRIDE', 'negative_stock_authorisation_mode' => 'PRIOR_APPROVAL']);
        $actor = PurchasingActor::make(['inventory.negative-stock.request']);
        $input = ['item_id' => $this->item->id, 'location_id' => $a->id, 'movement_class' => 'MATERIAL_CONSUMPTION',
            'requested_qty' => '5.0000', 'source_type' => 'JOB_MATERIAL', 'source_id' => 987654,
            'reason_code' => 'PAPERWORK_DELAY', 'explanation' => 'Awaiting receipt paperwork'];
        $request = app(RequestNegativeStockOverride::class)($input, $actor);
        $this->assertSame('JOB_MATERIAL', $request->source_type);
        $this->assertSame($request->id, app(RequestNegativeStockOverride::class)($input, $actor)->id);
        $this->assertSame(0, StockMove::query()->count());
        $this->assertNoBinding();
    }

    private function assertNoBinding(): void
    {
        $answer = (new InventoryJobSourceApplicability)->assess(1);
        $this->assertSame(FamilyAssessment::NOT_APPLICABLE, $answer->status);
        $this->assertSame([], $answer->supportedBindings, 'Generic provenance must never be returned as a supported Job allocation.');
    }
}
