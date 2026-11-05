<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Exceptions\DomainInvariantException;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Sales\Actions\Access\GrantQuotationAccess;
use App\Modules\Sales\Actions\IssueQuotation;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\Documents\QuotationDocumentLocator;
use App\Modules\Sales\Support\PermissionVocabulary as Duty;
use App\Modules\Sales\Support\Quotation\QuotationAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Modules\Sales\Concerns\BuildsQuotationOwnership;
use Tests\TestCase;

/** Isolated duty fixtures prove sharing never supplies special-operation eligibility; no demo/production holder receives these rights. */
class QuotationShareOperationBoundaryTest extends TestCase
{
    use BuildsQuotationOwnership, RefreshDatabase;

    public function test_named_prepare_does_not_supply_issue_or_document_eligibility_even_with_independent_duty_rows(): void
    {
        $this->setUpOwnership();
        $this->activateOwnershipDuties();
        $extra = [Duty::QUOTATION_ACCESS_DELEGATE, Duty::QUOTATION_ISSUE, Duty::DOCUMENT_VIEW, Duty::DOCUMENT_DOWNLOAD];
        foreach ($extra as $duty) {
            Permission::findOrCreate($duty, 'web');
        }
        $this->app->instance(SalesDutyManifest::class, app(SalesDutyManifest::class)->withActivated($extra));
        $this->preparer->givePermissionTo(Duty::QUOTATION_ACCESS_DELEGATE);
        $this->colleague->givePermissionTo([Duty::QUOTATION_VIEW, Duty::QUOTATION_PREPARE, Duty::QUOTATION_ISSUE, Duty::DOCUMENT_VIEW, Duty::DOCUMENT_DOWNLOAD]);
        foreach ([Duty::QUOTATION_VIEW, Duty::QUOTATION_PREPARE] as $duty) {
            AccessScopeLimit::create(['user_id' => $this->colleague->id, 'permission' => $duty, 'scope' => 'OWN_OR_DELEGATED']);
        }
        $family = $this->nativeFamily();
        $revision = $family->revisions()->firstOrFail();
        app(GrantQuotationAccess::class)($this->preparer, $family->id, $this->colleague->id, 'PREPARE', 1, 0, $this->key(), 'Ordinary prepare share only');
        $this->assertTrue(DB::transaction(fn () => app(QuotationAccess::class)->queryFor($this->colleague, [Duty::QUOTATION_VIEW, Duty::QUOTATION_PREPARE])->whereKey($family->id)->exists()));
        $this->assertFalse(DB::transaction(fn () => app(QuotationAccess::class)->queryFor($this->colleague, [Duty::QUOTATION_VIEW, Duty::QUOTATION_ISSUE])->whereKey($family->id)->exists()));
        $receipts = DB::table('sales_quotation_operation_receipts')->count();
        try {
            app(IssueQuotation::class)($this->colleague, $revision->id, $revision->input_generation, $this->key());
            $this->fail('Named preparation cannot supply issue eligibility.');
        } catch (DomainInvariantException $failure) {
            $this->assertSame('The quotation was not found.', $failure->getMessage());
        }
        foreach ([$revision->id, 999999999] as $id) {
            try {
                DB::transaction(fn () => app(QuotationDocumentLocator::class)->authorise($this->colleague, $id, 'view documents'));
                $this->fail('A named share cannot supply document eligibility.');
            } catch (DomainInvariantException $failure) {
                $this->assertSame('DOCUMENT_NOT_FOUND', $failure->reasonCode);
                $this->assertSame('The quotation document was not found.', $failure->getMessage());
            }
        }
        $this->assertSame($receipts, DB::table('sales_quotation_operation_receipts')->count());
        $this->assertSame('DRAFT', $revision->fresh()->state);
        $this->assertSame(1, DB::table('quotation_access_grants')->count());
    }
}
