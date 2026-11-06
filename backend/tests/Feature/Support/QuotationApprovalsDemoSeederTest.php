<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\User;
use App\Modules\Approvals\Models\ApprovalDecision;
use App\Modules\Approvals\Models\ApprovalPolicy;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Models\ApprovalSubjectSetting;
use App\Modules\Sales\Actions\AssessQuotationIssueReadiness;
use App\Modules\Sales\Actions\ReadQuotationApproval;
use App\Modules\Sales\Models\QuotationIssue;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as Sales;
use App\Modules\Sales\Support\Quotation\Approval\QuotationApprovalSubject;
use Database\Seeders\Demo\Sales\QuotationApprovalsDemoSeeder;
use Database\Seeders\Demo\Sales\QuotationIssuingDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The approvals demo is local-preview only, goes through the real Actions, leaves the duties STAGED, and a rerun adds nothing. */
final class QuotationApprovalsDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_writes_nothing_outside_the_local_preview(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();

        $this->assertSame(0, ApprovalRequest::query()->where('subject_type', QuotationApprovalSubject::CODE)->count());
        $this->assertSame(0, ApprovalSubjectSetting::query()->where('subject_type', QuotationApprovalSubject::CODE)->count());
        $this->assertNull(User::query()->where('email', QuotationApprovalsDemoSeeder::APPROVER)->first());
    }

    public function test_the_local_preview_builds_every_scenario_through_the_real_actions_and_a_rerun_adds_nothing(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();
        config(['sales.dev_preview.quotation_duties' => true]);
        $this->app->detectEnvironment(fn (): string => 'local');
        $this->app->forgetInstance(SalesDutyManifest::class);
        $this->seed(QuotationIssuingDemoSeeder::class);
        $this->seed(QuotationApprovalsDemoSeeder::class);
        $counts = fn (): array => [QuotationRevision::query()->count(), ApprovalRequest::query()->count(), ApprovalDecision::query()->count(), QuotationIssue::query()->count(), ApprovalPolicy::query()->count()];
        $first = $counts();
        $this->seed(QuotationApprovalsDemoSeeder::class);
        $this->assertSame($first, $counts());

        $requests = ApprovalRequest::query()->where('subject_type', QuotationApprovalSubject::CODE)->orderBy('id')->get();
        $this->assertSame(['PENDING', 'APPROVED', 'APPROVED', 'REJECTED', 'WITHDRAWN', 'INVALIDATED'], $requests->map(fn (ApprovalRequest $r): string => $r->status->value)->all());
        $this->assertSame(1, QuotationIssue::query()->whereNotNull('approval_request_id')->count(), 'only the issued scenario names its approval');

        $admin = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        $approver = User::query()->where('email', QuotationApprovalsDemoSeeder::APPROVER)->firstOrFail();
        $byId = fn (int $i): QuotationRevision => QuotationRevision::query()->findOrFail($requests[$i]->subject_id);
        $codes = fn (QuotationRevision $r): array => array_column(app(AssessQuotationIssueReadiness::class)($admin, $r->id)['blockers'], 'code');
        $this->assertContains('APPROVAL_PENDING', $codes($byId(0)));
        $this->assertSame([], $codes($byId(1)), 'approved and ready to issue');
        $this->assertContains('APPROVAL_REJECTED', $codes($byId(3)));
        $this->assertContains('APPROVAL_REQUIRED', $codes($byId(4)));
        $this->assertContains('APPROVAL_REQUIRED', $codes($byId(5)), 'edited after approval: a new approval is needed');

        // The approver can decide the waiting one (the duty is ACTIVE in the local preview, owner decision); the preparer never can.
        $shipped = app(SalesDutyManifest::class);
        $this->assertTrue($shipped->isActive(Sales::QUOTATION_APPROVE));
        $this->assertTrue(app(ReadQuotationApproval::class)($approver, $byId(0)->id)['can']['decide']);
        $this->assertFalse(app(ReadQuotationApproval::class)($admin, $byId(0)->id)['can']['decide']);

        // Nothing lasting: the seeder left the container's manifest as the local preview built it, and the shipped manifest stays STAGED.
        $this->assertSame($shipped, app(SalesDutyManifest::class));
        $this->assertFalse(SalesDutyManifest::shipped()->isActive(Sales::QUOTATION_APPROVE));
    }
}
