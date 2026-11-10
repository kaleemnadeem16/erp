<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Sales;

use App\Models\Currency;
use App\Models\User;
use App\Modules\Approvals\Actions\ActivateApprovalPolicy;
use App\Modules\Approvals\Actions\CreateApprovalPolicy;
use App\Modules\Approvals\Actions\SaveApprovalSubjectSetting;
use App\Modules\Approvals\Models\ApprovalPolicy;
use App\Modules\Approvals\Models\ApprovalSubjectSetting;
use App\Modules\Approvals\Support\ApprovalDecisionKind;
use App\Modules\Approvals\Support\ApprovalMode;
use App\Modules\Inventory\Models\Item;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Actions\CreateQuotationDraft;
use App\Modules\Sales\Actions\DecideQuotationApproval;
use App\Modules\Sales\Actions\IssueQuotation;
use App\Modules\Sales\Actions\RequestQuotationApproval;
use App\Modules\Sales\Actions\UpdateQuotationDraft;
use App\Modules\Sales\Actions\WithdrawQuotationApproval;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Authority\SalesDevPreview;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as Sales;
use App\Modules\Sales\Support\Quotation\Approval\QuotationApprovalSubject;
use App\Support\CompanyContext;
use App\Support\Demo\DemoQuotationReplay;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Quotation discount APPROVAL (ADR 0158) for XYZ Paints, through the module's own Actions: the demo administrator prepares and requests; a separate demo approver decides.
 *
 * Local only, like the issuing demo: it runs when `SalesDevPreview::enabled()` (APP_ENV=local AND SALES_DEV_QUOTATION_DUTIES=true); anywhere else it logs one line and writes nothing.
 * `sales.quotation.approve` (and discount, override, issue) are STAGED and stay STAGED: they are switched on only while this seeder runs and the manifest is put back afterwards.
 * The approver persona is given the approve and view permission rows (demo data, exactly as the other quotation seeders grant the administrator); nothing is added to the dev preview,
 * so signing in as the approver to decide in the browser needs the owner to activate the duty. The company's approval configuration is created only when absent: mode REQUIRED and one
 * policy with a single "Sales approver" step. Create-only and company-scoped: every request key replays, nothing existing is edited, and another company's data is never read.
 * Needs the issuing demo first (the declared demo service tax and the control set) so an approved draft can really be issued.
 *
 *  - WAITING    A small final-net override on a service line for Metro Industries, requested and waiting for the approver.
 *  - APPROVED   An override for Alpha Decorators, approved: readiness says it can be issued (not issued).
 *  - ISSUED     The same shape, approved and issued: the official quotation names the approval and keeps the reduction.
 *  - REJECTED   A 25% discount, rejected with a written reason.
 *  - WITHDRAWN  A request the preparer took back to correct the draft.
 *  - CHANGED    Approved, then the override was edited: the approval no longer counts and a new one is needed.
 */
final class QuotationApprovalsDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    public const APPROVER = 'demo-quote-approver@erp.x1techs.com';

    private const DUTIES = [Sales::QUOTATION_VIEW, Sales::QUOTATION_PREPARE, Sales::QUOTATION_ISSUE, Sales::QUOTATION_DISCOUNT, Sales::QUOTATION_PRICE_OVERRIDE, Sales::QUOTATION_APPROVE];

    public function run(): void
    {
        $this->seedFor(app(CompanyContext::class)->id());
    }

    public function seedFor(int $companyId): void
    {
        $this->ensureDemoEnvironment();
        if (! SalesDevPreview::enabled()) {
            Log::info('Quotation approvals demo skipped: needs APP_ENV=local and SALES_DEV_QUOTATION_DUTIES=true (the approve duty stays STAGED everywhere else).');

            return;
        }
        $admin = User::query()->where('company_id', $companyId)->where('email', config('demo.user.email'))->firstOrFail();
        $alpha = Party::query()->where('code', 'cus-alpha')->first();
        $metro = Party::query()->where('code', 'cus-metro')->first();
        $service = Item::query()->where('code', 'demo-site-application')->first();
        $pkr = Currency::query()->where('code', 'PKR')->first();
        if ($alpha === null || $metro === null || $service === null || $pkr === null || ! DB::table('tax_quotation_declaration_versions')->where('code', 'demo-illustrative')->exists()) {
            Log::info('Quotation approvals demo skipped: the demo customers, service item or the demo tax declaration (QuotationIssuingDemoSeeder) do not exist yet.');

            return;
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->demoCompanyId());
        foreach (self::DUTIES as $duty) {
            Permission::findOrCreate($duty, 'web');
        }
        // Only what this run adds to the login is taken away again afterwards; the approver persona's own view/approve rows are its definition.
        $adminDuties = array_values(array_diff(self::DUTIES, [Sales::QUOTATION_APPROVE]));
        $heldBefore = $admin->getDirectPermissions()->pluck('name')->all();
        $admin->givePermissionTo($adminDuties);
        $approver = $this->demoMember(self::APPROVER, 'Demo Quotation Approver');
        $approver->givePermissionTo([Sales::QUOTATION_VIEW, Sales::QUOTATION_APPROVE]);

        $shipped = app(SalesDutyManifest::class);
        $staged = array_values(array_filter(self::DUTIES, fn (string $duty): bool => ! $shipped->isActive($duty)));
        app()->instance(SalesDutyManifest::class, $staged === [] ? $shipped : $shipped->withActivated($staged));
        try {
            DB::transaction(function () use ($admin, $approver, $alpha, $metro, $service, $pkr): void {
                $this->configure($admin);
                $this->scenarios($admin, $approver, $alpha, $metro, $service, $pkr);
            });
        } finally {
            app()->instance(SalesDutyManifest::class, $shipped);
            $added = array_values(array_diff($adminDuties, $heldBefore));
            if ($added !== []) {
                $admin->revokePermissionTo($added);
            }
        }
    }

    /** Created only when absent: a company that already chose a mode or a policy keeps it. */
    private function configure(User $admin): void
    {
        if (! ApprovalSubjectSetting::query()->where('subject_type', QuotationApprovalSubject::CODE)->exists()) {
            app(SaveApprovalSubjectSetting::class)(QuotationApprovalSubject::CODE, ApprovalMode::Required, $admin);
        }
        if (ApprovalPolicy::query()->where('subject_type', QuotationApprovalSubject::CODE)->exists()) {
            return;
        }
        $policy = app(CreateApprovalPolicy::class)([
            'subject_type' => QuotationApprovalSubject::CODE, 'code' => 'quotation-discounts', 'name' => 'Quotation discount sign-off', 'priority' => 0,
            'reason' => 'Demo: an example approval policy - a real company sets its own.', 'conditions' => [],
            'steps' => [['name' => 'Sales approver', 'stage' => 1, 'required_approvals' => 1, 'use_authority_limit' => false]],
        ], $admin);
        app(ActivateApprovalPolicy::class)($policy, $admin);
    }

    private function scenarios(User $admin, User $approver, Party $alpha, Party $metro, Item $service, Currency $pkr): void
    {
        $today = now()->format('Y-m-d');
        $month = now()->addMonths(2)->format('Y-m-d');
        $header = fn (Party $to): array => ['recipient_party_id' => $to->id, 'currency_id' => $pkr->id, 'pricing_date' => $today, 'tax_estimate_date' => $today, 'valid_through_local_date' => $month];
        $line = fn (array $more): array => ['source_kind' => 'SERVICE_ITEM', 'item_id' => $service->id, 'offered_quantity' => '2', 'unit_price' => '25000.0000'] + $more;
        $override = fn (string $amount, string $why): array => ['net_override' => ['amount' => $amount, 'reason' => $why]];

        // A scenario an earlier run already built is skipped whole (its dates came from that day; a later day would resubmit a different payload under the same key).
        $fresh = fn (string $marker): bool => ! DemoQuotationReplay::done($admin, "demo-appr-{$marker}");

        if ($fresh('waiting')) {
            $waiting = $this->draft($admin, 'waiting', $header($metro), $line($override('48500', 'Framework customer: small goodwill reduction on the site visit.')));
            $this->request($admin, 'waiting', $waiting);
        }

        if ($fresh('approved')) {
            $approved = $this->draft($admin, 'approved', $header($alpha), $line($override('48000', 'Repeat customer: reduced after the site visit.')));
            $this->request($admin, 'approved', $approved);
            $this->decide($approver, 'approved', $approved, ApprovalDecisionKind::Approved, 'Within what we agreed for repeat customers.');
        }

        if ($fresh('issued')) {
            $issued = $this->draft($admin, 'issued', $header($alpha), $line($override('47500', 'Repeat customer: reduced after the site visit.')));
            $this->request($admin, 'issued', $issued);
            $this->decide($approver, 'issued', $issued, ApprovalDecisionKind::Approved, 'Approved for this customer.');
            app(IssueQuotation::class)($admin, $issued->id, $issued->input_generation, 'demo-appr-issued-issue');
        }

        if ($fresh('rejected')) {
            $rejected = $this->draft($admin, 'rejected', $header($metro), $line(['discount' => ['kind' => 'PERCENT', 'value' => '25']]));
            $this->request($admin, 'rejected', $rejected);
            $this->decide($approver, 'rejected', $rejected, ApprovalDecisionKind::Rejected, 'Too deep for this customer: offer at most 10%.');
        }

        if ($fresh('withdrawn')) {
            $withdrawn = $this->draft($admin, 'withdrawn', $header($alpha), $line($override('46000', 'Reduced to match a competitor.')));
            $this->request($admin, 'withdrawn', $withdrawn);
            app(WithdrawQuotationApproval::class)($admin, $withdrawn->id, $withdrawn->input_generation, 'The customer asked for a different scope; the draft will be corrected first.', 'demo-appr-withdrawn-withdraw');
        }

        if ($fresh('changed')) {
            $changed = $this->draft($admin, 'changed', $header($alpha), $line($override('47000', 'Repeat customer.')));
            $this->request($admin, 'changed', $changed);
            $this->decide($approver, 'changed', $changed, ApprovalDecisionKind::Approved, 'Approved.');
            $lineId = (int) DB::table('quotation_draft_lines')->where('revision_id', $changed->id)->value('id');
            app(UpdateQuotationDraft::class)($admin, $changed->id, $changed->input_generation, 'demo-appr-changed-edit', ['update_lines' => [['id' => $lineId, 'net_override' => ['amount' => '45000', 'reason' => 'Increased reduction after the approval.']]]]);
        }
    }

    /**
     * Creates the draft with its one line; the returned revision carries the generation the later steps were reviewed at (replays return the same).
     *
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $line
     */
    private function draft(User $admin, string $marker, array $header, array $line): QuotationRevision
    {
        $created = app(CreateQuotationDraft::class)($admin, "demo-appr-{$marker}", $header);
        $generation = app(UpdateQuotationDraft::class)($admin, $created->revisionId, $created->inputGeneration, "demo-appr-{$marker}-lines", ['add_lines' => [$line]])->inputGeneration;
        $revision = QuotationRevision::query()->findOrFail($created->revisionId);
        $revision->input_generation = $generation;

        return $revision;
    }

    private function request(User $admin, string $marker, QuotationRevision $draft): void
    {
        app(RequestQuotationApproval::class)($admin, $draft->id, $draft->input_generation, "demo-appr-{$marker}-request");
    }

    private function decide(User $approver, string $marker, QuotationRevision $draft, ApprovalDecisionKind $decision, string $reason): void
    {
        app(DecideQuotationApproval::class)($approver, $draft->id, $draft->input_generation, $decision, $reason, "demo-appr-{$marker}-decide");
    }
}
