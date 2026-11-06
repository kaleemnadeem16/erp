<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Models\Company;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Modules\Sales\Actions\ApproveSalesPriceReference;
use App\Modules\Sales\Actions\BindQuotationAuthorityReferences;
use App\Modules\Sales\Actions\CreateQuotationDraft;
use App\Modules\Sales\Actions\ProposeSalesPriceReference;
use App\Modules\Sales\Actions\UpdateQuotationDraft;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\DiscountAuthority\AssessDiscountAuthority;
use App\Support\RateType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Modules\Sales\Concerns\BuildsDiscountAuthority;
use Tests\TestCase;

class DiscountAuthorityFxTest extends TestCase
{
    use BuildsDiscountAuthority;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDiscountAuthority();
    }

    public function test_foreign_currency_limits_use_the_pinned_base_exposure_not_the_transaction_amount(): void
    {
        $usd = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'Dollar', 'symbol' => '$', 'decimal_places' => 2]);
        $base = Company::findOrFail(1)->base_currency_id;
        $rate = ExchangeRate::create(['from_currency_id' => $usd->id, 'to_currency_id' => $base, 'rate' => '280.000000000', 'rate_date' => $this->today(), 'rate_type' => RateType::Spot, 'source' => 'manual']);
        $created = app(CreateQuotationDraft::class)($this->maker, $this->issueKey(), ['recipient_party_id' => $this->customer->id, 'currency_id' => $usd->id,
            'pricing_date' => $this->today(), 'valid_through_local_date' => now()->addMonth()->format('Y-m-d')]);
        app(UpdateQuotationDraft::class)($this->maker, $created->revisionId, $created->inputGeneration, $this->issueKey(), ['add_lines' => [[
            'source_kind' => 'MANUAL', 'description' => 'Foreign service', 'manual_measure' => 'job', 'offered_quantity' => '1', 'unit_price' => '100.0000', 'discount' => ['kind' => 'PERCENT', 'value' => '3'],
        ]]]);
        $revision = QuotationRevision::findOrFail($created->revisionId);
        $reference = app(ProposeSalesPriceReference::class)($this->maker, $this->issueKey(), $this->authorityGeneration(), [
            'code' => 'foreign-reference', 'source_kind' => 'PROJECT_BASELINE', 'source_key' => 'Verified foreign service', 'source_version' => '1', 'family_id' => $revision->family_id,
            'manual_measure' => 'job', 'currency_id' => $usd->id, 'unit_price' => '100.0000', 'effective_from' => $this->today(), 'priority' => 0, 'reason' => 'Test source',
        ]);
        app(ApproveSalesPriceReference::class)($this->checker, $reference->id, $this->issueKey(), $this->authorityGeneration(), 'Verified');
        app(BindQuotationAuthorityReferences::class)($this->maker, $revision->id, $revision->input_generation, $this->issueKey());
        $policy = $this->authorityPolicy(['bands' => [['rank' => 1, 'label' => 'Company-base ceiling', 'exception_only' => false, 'line_percent' => '5',
            'document_percent' => '5', 'line_base_amount' => '500.0000', 'document_base_amount' => '500.0000']]]);
        $this->authorityAssignment($policy, 1);
        $assessment = app(AssessDiscountAuthority::class)->assess($revision);
        $this->assertSame('3.0000', $assessment['measure']['reduction_amount']);
        $this->assertSame('840.0000', $assessment['measure']['reduction_base_amount']);
        $this->assertSame('28000.0000', $assessment['measure']['reference_base_amount']);
        $this->assertSame('27160.0000', $assessment['measure']['final_net_base_amount']);
        $this->assertSame('APPROVAL_REQUIRED', $assessment['result']);
        $rate->update(['rate' => '300.000000000']);
        $this->assertSame('840.0000', app(AssessDiscountAuthority::class)->assess($revision)['measure']['reduction_base_amount']);
    }
}
