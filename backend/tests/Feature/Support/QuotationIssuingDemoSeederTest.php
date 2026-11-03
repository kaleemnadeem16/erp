<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\User;
use App\Modules\Sales\Actions\AssessQuotationIssueReadiness;
use App\Modules\Sales\Models\QuotationIssue;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as Sales;
use App\Modules\Sales\Support\Quotation\QuotationAvailability;
use App\Modules\Tax\Support\TaxDutyManifest;
use Database\Seeders\Demo\Sales\QuotationIssuingDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The issuing demo is local-preview only, goes through the real Actions, grants nothing lasting, and a rerun adds nothing. */
final class QuotationIssuingDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_writes_nothing_outside_the_local_preview(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();

        $this->assertSame(0, QuotationIssue::query()->count());
        $this->assertSame(0, QuotationRevision::query()->count());
    }

    public function test_the_local_preview_builds_the_scenarios_through_the_real_actions_and_a_rerun_adds_nothing(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();
        config(['sales.dev_preview.quotation_duties' => true]);
        $this->app->detectEnvironment(fn (): string => 'local');
        $this->app->forgetInstance(SalesDutyManifest::class);
        $this->seed(QuotationIssuingDemoSeeder::class);
        $counts = [QuotationRevision::query()->count(), QuotationIssue::query()->count()];
        $this->seed(QuotationIssuingDemoSeeder::class);
        $this->assertSame($counts, [QuotationRevision::query()->count(), QuotationIssue::query()->count()]);

        $this->assertSame(6, QuotationRevision::query()->count());
        $this->assertSame(3, QuotationIssue::query()->count(), 'issued, expiring today and withdrawn');
        $states = QuotationRevision::query()->get()->map(fn (QuotationRevision $r): string => QuotationAvailability::of($r))->countBy()->all();
        $this->assertEquals([QuotationAvailability::DRAFT => 2, QuotationAvailability::AVAILABLE => 2, QuotationAvailability::WITHDRAWN => 1, QuotationAvailability::CANCELLED_DRAFT => 1], $states);

        // The ready draft is genuinely ready; the undeclared one is blocked by tax, never by a faked pass.
        $admin = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        $drafts = QuotationRevision::query()->where('state', 'DRAFT')->orderBy('id')->get();
        $ready = app(AssessQuotationIssueReadiness::class)($admin, $drafts[0]->id);
        $blocked = app(AssessQuotationIssueReadiness::class)($admin, $drafts[1]->id);
        $this->assertSame([true, []], [$ready['issue_ready'], $ready['blockers']]);
        $this->assertContains('TAX_CLASSIFICATION_REQUIRED', array_column($blocked['blockers'], 'code'));

        // Nothing lasting: staged duties are back to staged and the Tax duty is not held.
        $this->assertFalse(app(SalesDutyManifest::class)->isActive(Sales::QUOTATION_ISSUE));
        $this->assertFalse(app(TaxDutyManifest::class)->isActive(TaxDutyManifest::CONFIGURE));
        $this->assertFalse($admin->fresh()->hasPermissionTo(TaxDutyManifest::CONFIGURE));
    }
}
