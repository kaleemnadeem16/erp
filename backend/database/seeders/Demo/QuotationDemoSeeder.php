<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Currency;
use App\Models\User;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Actions\AppendCustomerDecisionEvidence;
use App\Modules\Sales\Actions\OpenCustomerDecisionCase;
use App\Modules\Sales\Actions\PublishQuotationControlSet;
use App\Modules\Sales\Models\CustomerDecisionCase;
use App\Modules\Sales\Models\QuotationControlSet;
use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as Sales;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

/**
 * Phase 4 quotation issuance, as XYZ Paints: the company's published control set (a mandatory line floor and an advisory one), through the real guarded Action, and the demo administrator's
 * quotation duties. The staged duties are switched on only while seeding. The named quotation drafts are `QuotationDraftsDemoSeeder`'s; until the Tax jurisdiction/classification source
 * exists none of them can be issued, and readiness honestly reports the tax blockers. A rerun adds nothing.
 */
final class QuotationDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    private const DUTIES = [Sales::QUOTATION_VIEW, Sales::QUOTATION_PREPARE, Sales::QUOTATION_ISSUE, Sales::QUOTATION_WITHDRAW, Sales::FINANCIAL_CONFIGURE, Sales::CUSTOMER_DECISION_EVIDENCE_RECORD];

    public function run(): void
    {
        $this->ensureDemoEnvironment();
        $admin = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        foreach (self::DUTIES as $duty) {
            Permission::findOrCreate($duty, 'web');
        }
        // Unrestricted administrator authority: the administrator holds every quotation duty (staged duties are still refused to everyone in a deployed environment).
        $admin->givePermissionTo(self::DUTIES);

        $shipped = app(SalesDutyManifest::class);
        app()->instance(SalesDutyManifest::class, $shipped->withActivated(self::DUTIES));
        try {
            DB::transaction(fn () => $this->seed($admin));
        } finally {
            app()->instance(SalesDutyManifest::class, $shipped);
        }
    }

    private function seed(User $admin): void
    {
        $pkr = Currency::query()->where('code', 'PKR')->firstOrFail();
        if (! QuotationControlSet::query()->exists()) {
            app(PublishQuotationControlSet::class)($admin, [
                ['ref' => 'line-floor', 'kind' => 'FLOOR', 'mode' => 'MANDATORY', 'limit' => '1000', 'currency_id' => $pkr->id],
                ['ref' => 'line-floor-advice', 'kind' => 'FLOOR', 'mode' => 'ADVISORY', 'limit' => '5000', 'currency_id' => $pkr->id],
            ], 'Demo: no line below Rs 1,000; advise review below Rs 5,000');
        }
        $this->intake($admin);
    }

    /** A customer's purchase order received for a demo quotation, with one observation and its later correction. Observations only: nothing here is an acceptance. */
    private function intake(User $admin): void
    {
        $party = Party::query()->where('code', 'cus-alpha')->first();
        $line = QuotationDraftLine::query()->orderBy('id')->first();
        if ($party === null || $line === null || CustomerDecisionCase::query()->exists()) {
            return;
        }
        $case = app(OpenCustomerDecisionCase::class)($admin, 'demo-intake-1', $party->id, 'PO-ALPHA-2026-0417', [
            ['intent_identity' => 'whole-quotation', 'family_id' => $line->family_id, 'original_scope_anchor_id' => $line->logical_scope_id],
        ]);
        $first = app(AppendCustomerDecisionEvidence::class)($admin, 'demo-intake-1-e1', $case->caseId, 0, [
            'source_kind' => 'PURCHASE_ORDER', 'external_reference' => 'PO-ALPHA-2026-0417', 'authority_verification_basis' => 'DOCUMENT_ON_FILE', 'occurrence_precision' => 'DATE',
            'occurred_at' => now()->format('Y-m-d').' 00:00:00+05', 'source_timezone' => 'Asia/Karachi', 'customer_representative_snapshot' => 'Procurement, Alpha Traders',
            'factual_description' => 'A purchase order arrived by email referring to the quotation; the quantities were not checked against the offer.',
        ]);
        app(AppendCustomerDecisionEvidence::class)($admin, 'demo-intake-1-e2', $case->caseId, $first->generation, [
            'source_kind' => 'VERBAL', 'authority_verification_basis' => 'UNVERIFIED', 'occurrence_precision' => 'UNKNOWN',
            'factual_description' => 'A caller said the purchase order covers only the first line; who called is not recorded.',
        ], $first->evidenceId);
    }
}
