<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\Currency;
use App\Modules\Sales\Actions\CreateQuotationDraft;
use App\Modules\Sales\Actions\EvaluateQuotationDiscountAuthority;
use App\Modules\Sales\Actions\ProposeSalesPriceReference;
use App\Modules\Sales\Support\DiscountAuthority\AssessDiscountAuthority;
use App\Modules\Sales\Support\DiscountAuthority\AuthorityParticipants;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Modules\Sales\Concerns\BuildsDiscountAuthority;
use Tests\TestCase;

class DiscountAuthorityEvidenceTest extends TestCase
{
    use BuildsDiscountAuthority;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDiscountAuthority();
    }

    public function test_restoring_a_revoked_grant_does_not_restore_the_old_authority_fingerprint(): void
    {
        $revision = $this->discount($this->authorityDraft(), '2');
        $policy = $this->authorityPolicy();
        $this->authorityAssignment($policy, 1);
        $first = app(AssessDiscountAuthority::class)->assess($revision);
        $this->maker->revokePermissionTo(PermissionVocabulary::QUOTATION_DISCOUNT);
        $revoked = app(AssessDiscountAuthority::class)->assess($revision);
        $this->assertSame('APPROVAL_REQUIRED', $revoked['result']);
        $this->maker->givePermissionTo(PermissionVocabulary::QUOTATION_DISCOUNT);
        $restored = app(AssessDiscountAuthority::class)->assess($revision);
        $this->assertSame('ROUTINE', $restored['result']);
        $this->assertNotSame($first['participant_epochs'], $restored['participant_epochs']);
        $this->assertNotSame($first['fingerprint'], $restored['fingerprint']);
        $this->assertContains($this->maker->id, app(AuthorityParticipants::class)->ids($revision));
    }

    public function test_same_total_currency_precision_changes_still_change_the_evidence_fingerprint(): void
    {
        $revision = $this->discount($this->authorityDraft(), '2');
        $policy = $this->authorityPolicy();
        $this->authorityAssignment($policy, 1);
        $before = app(AssessDiscountAuthority::class)->assess($revision);
        Currency::findOrFail($revision->currency_id)->update(['decimal_places' => 3]);
        $after = app(AssessDiscountAuthority::class)->assess($revision);
        $this->assertSame($before['measure']['reduction_amount'], $after['measure']['reduction_amount']);
        $this->assertNotSame($before['measure']['currency_quantum_places'], $after['measure']['currency_quantum_places']);
        $this->assertNotSame($before['fingerprint'], $after['fingerprint']);
    }

    public function test_missing_base_setup_cannot_create_a_reference_that_permanently_locks_the_incomplete_setup(): void
    {
        $company = Company::findOrFail(1);
        $base = $company->base_currency_id;
        $company->update(['base_currency_id' => null]);
        try {
            app(ProposeSalesPriceReference::class)($this->maker, 'no-base', $this->authorityGeneration(), []);
            $this->fail('A missing base currency must be refused before recording an immutable reference.');
        } catch (DomainInvariantException $e) {
            $this->assertSame('AUTHORITY_BASE_CURRENCY_REQUIRED', $e->reasonCode);
        }
        $this->assertDatabaseCount('sales_price_reference_versions', 0);
        $company->update(['base_currency_id' => $base]);
        $this->assertSame($base, app(CurrentCompany::class)->baseCurrencyId());
    }

    public function test_shadow_audit_keeps_missing_base_amounts_unknown_instead_of_blocking_or_inventing_zero(): void
    {
        $company = Company::findOrFail(1);
        $quoteCurrency = $company->base_currency_id;
        $company->update(['base_currency_id' => null]);
        $this->authorityMode();
        $draft = app(CreateQuotationDraft::class)($this->maker, $this->issueKey(), ['currency_id' => $quoteCurrency]);
        $result = app(EvaluateQuotationDiscountAuthority::class)($this->maker, $draft->revisionId, $draft->inputGeneration, 'unknown-base');
        $this->assertSame('UNEVALUABLE', $result['result']);
        $this->assertContains('AUTHORITY_BASE_CURRENCY_REQUIRED', $result['reasons']);
        $row = DB::table('sales_discount_evaluations')->where('id', $result['evaluation_id'])->first();
        $this->assertNull($row->base_currency_id);
        $this->assertNull($row->reference_amount);
        $this->assertNull($row->reduction_base_amount);
    }

    public function test_company_timezone_changes_invalidate_the_same_commercial_evidence(): void
    {
        $this->travelTo(now()->utc()->startOfDay()->addHours(12));
        try {
            Company::findOrFail(1)->update(['timezone' => 'Asia/Karachi']);
            $revision = $this->discount($this->authorityDraft(), '2');
            $policy = $this->authorityPolicy();
            $this->authorityAssignment($policy, 1);
            $before = app(AssessDiscountAuthority::class)->assess($revision);
            Company::findOrFail(1)->update(['timezone' => 'UTC']);
            $after = app(AssessDiscountAuthority::class)->assess($revision);
            $this->assertSame('ROUTINE', $after['result']);
            $this->assertSame($before['measure']['reduction_amount'], $after['measure']['reduction_amount']);
            $this->assertNotSame($before['company_timezone'], $after['company_timezone']);
            $this->assertNotSame($before['fingerprint'], $after['fingerprint']);
        } finally {
            $this->travelBack();
        }
    }
}
