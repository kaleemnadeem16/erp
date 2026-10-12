<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Currency;
use App\Models\Role;
use App\Models\User;
use App\Modules\Administration\Actions\ChangeMemberStatus;
use App\Modules\Administration\Actions\SyncMemberAccess;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Administration\Support\MemberStatus;
use App\Modules\Parties\Actions\CreateParty;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Actions\AddSalesNote;
use App\Modules\Sales\Actions\ChangeOpportunityStage;
use App\Modules\Sales\Actions\CloseOpportunity;
use App\Modules\Sales\Actions\CreateOpportunity;
use App\Modules\Sales\Actions\DecideLead;
use App\Modules\Sales\Actions\LinkLeadParty;
use App\Modules\Sales\Actions\MaintainSalesStages;
use App\Modules\Sales\Actions\RecordLead;
use App\Modules\Sales\Models\Lead;
use App\Modules\Sales\Support\OpportunityOutcome;
use App\Modules\Sales\Support\PermissionVocabulary as Sales;
use App\Support\CompanyContext;
use App\Support\PermissionRegistry;
use Database\Seeders\Demo\Concerns\CreatesDemoRolePersonas;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4 CRM foundation, as XYZ Paints: the suggested pipeline stages; an enquiry still new and unassigned; a qualified enquiry that produced two genuine deals
 * (furniture and installation); a disqualified one; a direct deal with a known value and a next action; a lost deal; and a deal whose owner has since been suspended,
 * so it shows as needing a new owner. Four personas: a representative, a manager (assign, close, stages), a read-only viewer, and a branch seller limited to their
 * units (who is refused until restricted visibility is built). Created once through the real Actions; a rerun adds nothing.
 */
final class SalesCrmDemoSeeder extends Seeder
{
    use CreatesDemoRolePersonas;
    use EnsuresDemoEnvironment;

    public const REPRESENTATIVE = 'demo-sales-rep@erp.x1techs.com';

    public const MANAGER = 'demo-sales-manager@erp.x1techs.com';

    public const VIEWER = 'demo-sales-viewer@erp.x1techs.com';

    public const BRANCH = 'demo-sales-branch@erp.x1techs.com';

    public const LEAVER = 'demo-sales-leaver@erp.x1techs.com';

    public function run(): void
    {
        $this->ensureDemoEnvironment();
        app(PermissionRegistry::class)->registerForTeam(1);
        $admin = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        $admin->givePermissionTo(Sales::all());
        $this->personas($admin);
        if (Lead::query()->where('enquirer_name', 'Rukhsana Baig')->exists()) {
            $this->duplicateIntake();

            return; // Immutable history and later manual progress are preserved.
        }
        DB::transaction(function () use ($admin): void {
            $rep = User::query()->where('email', self::REPRESENTATIVE)->firstOrFail();
            $stages = app(MaintainSalesStages::class)->seedSuggested($admin);
            $stage = fn (string $code): int => (int) collect($stages)->firstWhere('code', $code)?->id;
            $alpha = Party::query()->where('code', 'cus-alpha')->firstOrFail();
            $metro = Party::query()->where('code', 'cus-metro')->firstOrFail();
            $pkr = Currency::query()->where('code', 'PKR')->firstOrFail();
            $fatima = Party::query()->where('code', 'ind-fatima')->first() ?? app(CreateParty::class)(['code' => 'ind-fatima', 'name' => 'Fatima Khan', 'kind' => 'INDIVIDUAL'], $admin);

            // A new, unassigned enquiry.
            app(RecordLead::class)(['enquirer_name' => 'Rukhsana Baig', 'enquirer_organisation' => 'Baig Interiors', 'enquirer_email' => 'rukhsana@baig.example', 'source' => 'Website',
                'description' => 'Asking about low-VOC paint for a showroom.'], $rep);

            // A qualified enquiry that produced two genuine deals.
            $lead = app(RecordLead::class)(['enquirer_name' => 'Aisha Rehman', 'enquirer_organisation' => 'Alpha Decorators', 'source' => 'Referral',
                'description' => 'New head-office fit-out: furniture and installation.'], $rep);
            app(LinkLeadParty::class)($lead, $alpha->id, $rep);
            $furniture = app(CreateOpportunity::class)(['title' => 'Head-office furniture', 'conversion_key' => 'demo-furniture', 'expected_amount' => '500000', 'expected_currency_id' => $pkr->id,
                'stage_id' => $stage('PROPOSAL'), 'next_action' => 'Send the revised quotation', 'next_action_on' => now()->addDays(3)->toDateString()], $rep, $lead)['opportunity'];
            app(CreateOpportunity::class)(['title' => 'Head-office installation', 'conversion_key' => 'demo-installation', 'expected_amount' => '300000', 'expected_currency_id' => $pkr->id,
                'stage_id' => $stage('NEGOTIATION')], $rep, $lead);
            app(AddSalesNote::class)($furniture, 'Aisha prefers delivery in two phases.', $rep);

            // A disqualified enquiry.
            $out = app(RecordLead::class)(['enquirer_name' => 'Imran Siddiqui', 'source' => 'Walk-in', 'description' => 'Wanted a one-bedroom repaint in another city.'], $rep);
            app(DecideLead::class)->disqualify($out, $rep, 'Outside our service area', 'demo-lead-out-of-area', $out->latestDecisionEventId());

            // A direct deal (no enquiry) for an individual buyer, with a known value, and one for an organisation with no value yet.
            app(CreateOpportunity::class)(['title' => 'Plant room refurbishment', 'party_id' => $metro->id, 'expected_amount' => '1200000', 'expected_currency_id' => $pkr->id,
                'expected_close_on' => now()->addDays(45)->toDateString(), 'stage_id' => $stage('PROPOSAL'), 'next_action' => 'Site visit', 'next_action_on' => now()->addDays(7)->toDateString()], $rep);
            app(CreateOpportunity::class)(['title' => 'Home repaint — Fatima Khan', 'party_id' => $fatima->id], $rep);

            // A lost deal, with its reason.
            $lost = app(CreateOpportunity::class)(['title' => 'Warehouse floor coating', 'party_id' => $alpha->id, 'expected_amount' => '250000', 'expected_currency_id' => $pkr->id], $rep)['opportunity'];
            app(ChangeOpportunityStage::class)($lost, $stage('NEGOTIATION'), $rep);
            app(CloseOpportunity::class)($lost, OpportunityOutcome::Lost, 'Lost on price to a cheaper bidder', $admin);

            // A deal whose owner has since left: it is flagged, never silently reassigned.
            $leaver = User::query()->firstOrCreate(['email' => self::LEAVER], ['name' => 'Demo Sales Leaver', 'password' => config('demo.user.password'), 'status' => MemberStatus::Active]);
            $leaver->forceFill(['email_verified_at' => now()])->save();
            $leaver->givePermissionTo([Sales::OPPORTUNITY_MANAGE]);
            app(CreateOpportunity::class)(['title' => 'Showroom repaint (owner has left)', 'party_id' => $metro->id, 'owner_id' => $leaver->id], $admin);
            app(ChangeMemberStatus::class)($leaver->refresh(), MemberStatus::Suspended, 'Demo: left the company', $admin);
        });
        $this->duplicateIntake();
    }

    /** ADR 0111: an intake found to be a duplicate is disqualified while the two genuine deals already derived from it stay open. Added once, beside the original demo. */
    private function duplicateIntake(): void
    {
        $rep = User::query()->where('email', self::REPRESENTATIVE)->first();
        $alpha = Party::query()->where('code', 'cus-alpha')->first();
        if ($rep === null || $alpha === null || Lead::query()->where('enquirer_name', 'Bilal Chaudhry')->exists()) {
            return;
        }
        DB::transaction(function () use ($rep, $alpha): void {
            $lead = app(RecordLead::class)(['enquirer_name' => 'Bilal Chaudhry', 'enquirer_organisation' => 'Alpha Decorators', 'source' => 'Website',
                'description' => 'Submitted twice: the second form duplicates a running enquiry.'], $rep);
            app(LinkLeadParty::class)($lead, $alpha->id, $rep);
            app(CreateOpportunity::class)(['title' => 'Showroom lighting', 'conversion_key' => 'demo-dup-lighting'], $rep, $lead);
            app(CreateOpportunity::class)(['title' => 'Showroom signage', 'conversion_key' => 'demo-dup-signage'], $rep, $lead);
            app(DecideLead::class)->disqualify($lead->refresh(), $rep, 'Duplicate intake: already being pursued', 'demo-lead-duplicate', $lead->latestDecisionEventId());
        });
    }

    private function personas(User $admin): void
    {
        $read = [Sales::PIPELINE_VIEW];
        $work = [Sales::LEAD_MANAGE, Sales::OPPORTUNITY_MANAGE];
        $this->rolePersona($admin, 'Sales representative', 'Records enquiries and works their deals: notes, stages, next actions. Cannot reassign, close or configure.', [...$read, ...$work, 'parties.party.manage'], self::REPRESENTATIVE, 'Demo Sales Rep');
        $this->rolePersona($admin, 'Sales manager', 'Everything a representative does, plus assigning owners, closing and reopening, and configuring the pipeline. The name confers nothing: each duty is a capability.', [...$read, ...$work, Sales::OPPORTUNITY_ASSIGN, Sales::OPPORTUNITY_CLOSE, Sales::STAGE_MANAGE, 'parties.party.manage'], self::MANAGER, 'Demo Sales Manager');
        $this->rolePersona($admin, 'Sales viewer', 'Reads the pipeline; changes nothing.', $read, self::VIEWER, 'Demo Sales Viewer');
        $this->rolePersona($admin, 'Branch seller (limited)', 'Works deals, but is limited to their own units. Restricted visibility is not built yet, so Sales refuses this person rather than showing everything.', [...$read, ...$work], self::BRANCH, 'Demo Branch Seller');
        $branch = User::query()->where('email', self::BRANCH)->firstOrFail();
        $role = Role::query()->where('team_id', app(CompanyContext::class)->id())->where('name', 'Branch seller (limited)')->firstOrFail();
        if (! AccessScopeLimit::query()->where('user_id', $branch->id)->exists()) {
            app(SyncMemberAccess::class)($branch, ['role_ids' => [$role->id], 'direct_permissions' => [], 'scope_limits' => [['role_id' => $role->id, 'scope' => AccessScopeLimit::MY_UNITS]], 'reason' => 'Demo: limited to their own units.'], $admin);
        }
    }
}
