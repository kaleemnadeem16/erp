<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales\Concerns;

use App\Models\User;
use App\Modules\Sales\Actions\ActivateDiscountAuthorityPolicy;
use App\Modules\Sales\Actions\ApproveSalesPriceReference;
use App\Modules\Sales\Actions\AssignDiscountAuthority;
use App\Modules\Sales\Actions\BindQuotationAuthorityReferences;
use App\Modules\Sales\Actions\CreateDiscountAuthorityPolicy;
use App\Modules\Sales\Actions\ProposeSalesPriceReference;
use App\Modules\Sales\Actions\SetDiscountAuthorityMode;
use App\Modules\Sales\Actions\UpdateQuotationDraft;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Models\SalesDiscountAssignment;
use App\Modules\Sales\Models\SalesDiscountBand;
use App\Modules\Sales\Models\SalesDiscountPolicy;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as Duty;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

trait BuildsDiscountAuthority
{
    use BuildsIssuableQuotations;

    protected function setUpDiscountAuthority(): void
    {
        $this->setUpIssuing();
        $names = [Duty::FINANCIAL_CONFIGURE, Duty::PRICE_REFERENCE_APPROVE, Duty::QUOTATION_DISCOUNT, Duty::QUOTATION_PRICE_OVERRIDE, Duty::QUOTATION_APPROVE];
        $this->app->instance(SalesDutyManifest::class, app(SalesDutyManifest::class)->withActivated($names));
        foreach ($names as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $this->maker->givePermissionTo([Duty::FINANCIAL_CONFIGURE, Duty::QUOTATION_DISCOUNT, Duty::QUOTATION_PRICE_OVERRIDE]);
        $this->checker->givePermissionTo([Duty::PRICE_REFERENCE_APPROVE, Duty::QUOTATION_APPROVE]);
    }

    protected function authorityGeneration(): int
    {
        return (int) DB::table('sales_authority_heads')->where('company_id', 1)->value('generation');
    }

    protected function authorityDraft(): QuotationRevision
    {
        $revision = $this->preparedDraft();
        $optional = $revision->lines()->where('membership', 'OPTIONAL')->firstOrFail();
        app(UpdateQuotationDraft::class)($this->maker, $revision->id, $revision->input_generation, $this->issueKey(), ['update_lines' => [['id' => $optional->id, 'manual_measure' => 'extra']]]);
        foreach (['job' => '100.0000', 'extra' => '30.0000'] as $measure => $price) {
            $reference = app(ProposeSalesPriceReference::class)($this->maker, $this->issueKey(), $this->authorityGeneration(), [
                'code' => 'authority-'.$revision->family_id.'-'.$measure, 'source_kind' => 'PROJECT_BASELINE', 'source_key' => 'Test approved project', 'source_version' => '1',
                'family_id' => $revision->family_id, 'manual_measure' => $measure, 'currency_id' => $revision->currency_id, 'unit_price' => $price,
                'effective_from' => $this->today(), 'priority' => 0, 'reason' => 'Test independent baseline',
            ]);
            app(ApproveSalesPriceReference::class)($this->checker, $reference->id, $this->issueKey(), $this->authorityGeneration(), 'Verified test reference');
        }
        app(BindQuotationAuthorityReferences::class)($this->maker, $revision->id, $revision->refresh()->input_generation, $this->issueKey());

        return $revision->refresh();
    }

    /** @param array<string, mixed> $over */
    protected function authorityPolicy(array $over = []): SalesDiscountPolicy
    {
        $bands = [];
        foreach ([[0, '0', '0.0000'], [1, '3', '25000.0000'], [2, '7', '100000.0000'], [3, '12', '500000.0000']] as [$rank, $percent, $amount]) {
            $bands[] = ['rank' => $rank, 'label' => 'DEMO ONLY '.$rank, 'exception_only' => false, 'line_percent' => $percent, 'line_base_amount' => $amount,
                'document_percent' => $percent, 'document_base_amount' => $amount, 'input_percent' => $percent, 'input_base_amount' => $amount];
        }
        $bands[] = ['rank' => 4, 'label' => 'Explicit independent exception', 'exception_only' => true];
        $input = array_replace(['code' => 'test-policy', 'priority' => 0, 'operation' => 'ANY', 'enforcement' => 'BOTH', 'effective_from' => $this->today(),
            'expiry_calendar_days' => 14, 'reason' => 'Test configurable authority', 'bands' => $bands], $over);
        $policy = app(CreateDiscountAuthorityPolicy::class)($this->maker, $this->issueKey(), $this->authorityGeneration(), $input);
        app(ActivateDiscountAuthorityPolicy::class)($this->maker, $policy->id, $this->issueKey(), $this->authorityGeneration(), 'Test policy activation');

        return $policy;
    }

    /** @param array<string, mixed> $over */
    protected function authorityAssignment(SalesDiscountPolicy $policy, int $rank, ?User $person = null, array $over = []): SalesDiscountAssignment
    {
        $band = SalesDiscountBand::query()->where('policy_id', $policy->id)->where('rank', $rank)->firstOrFail();

        return app(AssignDiscountAuthority::class)($this->maker, $this->issueKey(), $this->authorityGeneration(), array_replace([
            'user_id' => ($person ?? $this->maker)->id, 'band_id' => $band->id, 'purpose' => 'ROUTINE', 'effective_from' => $this->today(), 'reason' => 'Test named authority',
        ], $over));
    }

    protected function authorityMode(string $mode = 'SHADOW'): void
    {
        app(SetDiscountAuthorityMode::class)($this->maker, $mode, $this->issueKey(), $this->authorityGeneration(), 'Test company mode');
    }

    protected function discount(QuotationRevision $revision, string $percent): QuotationRevision
    {
        if ($percent === '0') {
            return $revision;
        }
        $line = $revision->lines()->where('membership', 'BASE')->firstOrFail();
        app(UpdateQuotationDraft::class)($this->maker, $revision->id, $revision->refresh()->input_generation, $this->issueKey(), ['update_lines' => [['id' => $line->id, 'discount' => ['kind' => 'PERCENT', 'value' => $percent]]]]);

        return $revision->refresh();
    }
}
