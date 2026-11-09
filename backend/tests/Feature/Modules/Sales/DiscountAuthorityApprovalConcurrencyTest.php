<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Exceptions\DomainInvariantException;
use App\Modules\Approvals\Actions\DecideApproval;
use App\Modules\Approvals\Models\ApprovalPolicy;
use App\Modules\Approvals\Models\ApprovalSubjectSetting;
use App\Modules\Approvals\Support\ApprovalDecisionKind;
use App\Modules\Approvals\Support\ApprovalMode;
use App\Modules\Approvals\Support\ApprovalPolicyStatus;
use App\Modules\Sales\Actions\RequestQuotationApproval;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as Duty;
use App\Modules\Sales\Support\Quotation\Approval\QuotationApprovals;
use App\Modules\Sales\Support\Quotation\Approval\QuotationApprovalSubject;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Modules\Sales\Concerns\BuildsDiscountAuthority;
use Tests\Feature\Security\Concerns\TwoSessionRace;
use Tests\TestCase;

/** Direct engine decisions on separate real PostgreSQL sessions, with committed Sales fixtures. */
class DiscountAuthorityApprovalConcurrencyTest extends TestCase
{
    use BuildsDiscountAuthority;
    use DatabaseMigrations;
    use TwoSessionRace;

    public function runDatabaseMigrations(): void
    {
        $this->refreshTestDatabase();
        $this->beforeApplicationDestroyed(function (): void {
            DB::setDefaultConnection(config('database.default'));
            $this->artisan('migrate:fresh', $this->migrateFreshUsing());
            RefreshDatabaseState::$migrated = false;
        });
    }

    /** @return array<string, array{string}> */
    public static function changes(): array
    {
        return ['unchanged control' => ['control'], 'decision before configuration' => ['decision-first'], 'suspension' => ['suspension'], 'permission revocation' => ['permission'], 'employee authority revocation' => ['assignment'], 'changed source' => ['source']];
    }

    #[DataProvider('changes')]
    public function test_change_committed_while_the_decision_waits_is_rechecked(string $change): void
    {
        $this->setUpDiscountAuthority();
        Permission::findOrCreate(Duty::AUTHORITY_EVIDENCE_VIEW, 'web');
        $this->app->instance(SalesDutyManifest::class, app(SalesDutyManifest::class)->withActivated([Duty::AUTHORITY_EVIDENCE_VIEW]));
        $this->checker->givePermissionTo([Duty::AUTHORITY_EVIDENCE_VIEW, Duty::QUOTATION_VIEW]);
        $revision = $this->discount($this->authorityDraft(), '8');
        $policy = $this->authorityPolicy();
        $assignment = $this->authorityAssignment($policy, 3, $this->checker, ['purpose' => 'APPROVE']);
        $this->authorityMode('ENFORCE');
        ApprovalSubjectSetting::query()->create(['subject_type' => QuotationApprovalSubject::CODE, 'mode' => ApprovalMode::Required]);
        $route = ApprovalPolicy::query()->create(['subject_type' => QuotationApprovalSubject::CODE, 'code' => 'race-review', 'version_no' => 1, 'name' => 'Independent review', 'priority' => 0,
            'status' => ApprovalPolicyStatus::Active, 'reason' => 'Test routing', 'changed_by' => $this->maker->id]);
        $route->steps()->create(['stage' => 1, 'name' => 'Review', 'required_approvals' => 1]);
        app(RequestQuotationApproval::class)($this->maker, $revision->id, $revision->input_generation, $this->issueKey());
        $request = app(QuotationApprovals::class)->latest($revision->id);
        $holder = $this->raceSession();
        $outcome = $this->raceAction($holder, 'select company_id from company_access_generations order by company_id for update',
            function () use ($change, $request, $assignment): void {
                if ($change === 'decision-first') {
                    $checked = false;
                    DB::listen(function ($query) use (&$checked, $assignment): void {
                        if ($checked || ! str_contains($query->sql, 'approval_requests') || ! str_contains($query->sql, 'for update')) {
                            return;
                        }
                        $checked = true;
                        $writer = $this->raceSession();
                        $writer->exec("SET lock_timeout = '150ms'");
                        try {
                            $writer->exec("insert into sales_discount_revocations (company_id, assignment_id, actor_id, reason) values (1, {$assignment->id}, {$this->maker->id}, 'Test concurrent writer')");
                            $this->fail('Configuration must wait for the decision transaction.');
                        } catch (\PDOException $e) {
                            $this->assertSame('55P03', $e->getCode());
                        }
                    });
                }
                app(DecideApproval::class)($request, $this->checker, ApprovalDecisionKind::Approved);
            },
            function (PDO $pdo) use ($change, $assignment, $revision): void {
                match ($change) {
                    'control', 'decision-first' => 0,
                    'suspension' => $pdo->exec("update users set status = 'SUSPENDED' where id = {$this->checker->id}"),
                    'permission' => $pdo->exec("delete from model_has_permissions where model_id = {$this->checker->id} and permission_id = (select id from permissions where name = 'sales.quotation.approve' and guard_name = 'web')"),
                    'assignment' => $pdo->exec("insert into sales_discount_revocations (company_id, assignment_id, actor_id, reason) values (1, {$assignment->id}, {$this->maker->id}, 'Test revocation')"),
                    'source' => $pdo->exec("update quotation_draft_lines set unit_price = unit_price + 1 where revision_id = {$revision->id}"),
                };
            });
        if (in_array($change, ['control', 'decision-first'], true)) {
            $this->assertTrue($outcome['success'], (string) $outcome['message']);
            $this->assertSame(1, $request->decisions()->count());
        } else {
            $this->assertFalse($outcome['success'], (string) $outcome['message']);
            $this->assertContains($outcome['class'], [AuthorizationException::class, DomainInvariantException::class]);
            $this->assertSame(0, $request->decisions()->count());
        }
    }
}
