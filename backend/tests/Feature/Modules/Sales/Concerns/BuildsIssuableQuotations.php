<?php

namespace Tests\Feature\Modules\Sales\Concerns;

use App\Models\Company;
use App\Models\Currency;
use App\Models\User;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Actions\CreateQuotationDraft;
use App\Modules\Sales\Actions\UpdateQuotationDraft;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Authority\DutyStatus;
use App\Modules\Sales\Support\Authority\SalesDuty;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\Issue\QuotationControlsProvider;
use App\Modules\Sales\Support\Quotation\Issue\QuotationTaxProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Sales\FakeQuotationControlsProvider;
use Tests\Support\Sales\FakeQuotationTaxProvider;

/** Fixtures for issuing: a maker (prepare), a checker (issue + view), a company with a base currency, and test-double tax and controls so a prepared draft is issuable. */
trait BuildsIssuableQuotations
{
    private User $maker;

    private User $checker;

    private Party $customer;

    private int $issueKeys = 0;

    protected function setUpIssuing(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        foreach ([PermissionVocabulary::QUOTATION_PREPARE, PermissionVocabulary::QUOTATION_VIEW, PermissionVocabulary::QUOTATION_ISSUE] as $duty) {
            Permission::findOrCreate($duty, 'web');
        }
        $pkr = Currency::firstOrCreate(['code' => 'PKR'], ['name' => 'Rupee', 'symbol' => 'Rs', 'decimal_places' => 2]);
        Company::findOrFail(1)->update(['base_currency_id' => $pkr->id]);
        $this->customer = Party::create(['code' => 'CUSTOMER', 'name' => 'Customer Ltd']);
        $this->maker = User::factory()->create();
        $this->maker->givePermissionTo(PermissionVocabulary::QUOTATION_PREPARE);
        $this->checker = User::factory()->create();
        $this->checker->givePermissionTo([PermissionVocabulary::QUOTATION_ISSUE, PermissionVocabulary::QUOTATION_VIEW]);
        $this->activateIssuing();
        $this->app->instance(QuotationTaxProvider::class, new FakeQuotationTaxProvider);
        $this->app->instance(QuotationControlsProvider::class, new FakeQuotationControlsProvider);
    }

    protected function activateIssuing(): void
    {
        $names = [PermissionVocabulary::QUOTATION_PREPARE, PermissionVocabulary::QUOTATION_VIEW, PermissionVocabulary::QUOTATION_ISSUE];
        $duties = array_map(fn (SalesDuty $duty): SalesDuty => in_array($duty->name, $names, true)
            ? new SalesDuty($duty->name, $duty->class, DutyStatus::Active, $duty->operation, $duty->projection, $duty->deliveryGate) : $duty, SalesDutyManifest::shipped()->all());
        $this->app->instance(SalesDutyManifest::class, new SalesDutyManifest($duties));
    }

    protected function issueKey(): string
    {
        return 'issue-key-'.++$this->issueKeys;
    }

    protected function today(): string
    {
        return now(Company::findOrFail(1)->timezone)->format('Y-m-d');
    }

    /** A prepared draft: a BASE line (2 x 100.0000 = 200.0000 net) and an OPTIONAL one (1 x 30.0000), valid for a month. */
    protected function preparedDraft(?string $validThrough = null, ?string $taxDate = null): QuotationRevision
    {
        $created = app(CreateQuotationDraft::class)($this->maker, $this->issueKey(), ['recipient_party_id' => $this->customer->id, 'pricing_date' => $this->today(), 'tax_estimate_date' => $taxDate ?? $this->today(),
            'valid_through_local_date' => $validThrough ?? now()->addMonth()->format('Y-m-d')]);
        app(UpdateQuotationDraft::class)($this->maker, $created->revisionId, $created->inputGeneration, $this->issueKey(), ['add_lines' => [
            ['source_kind' => 'MANUAL', 'description' => 'Survey', 'manual_measure' => 'job', 'offered_quantity' => '2', 'unit_price' => '100.0000'],
            ['source_kind' => 'MANUAL', 'description' => 'Optional extra', 'manual_measure' => 'job', 'offered_quantity' => '1', 'unit_price' => '30.0000', 'membership' => 'OPTIONAL'],
        ]]);

        return QuotationRevision::findOrFail($created->revisionId);
    }
}
