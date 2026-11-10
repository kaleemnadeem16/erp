<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Sales;

use App\Exceptions\DomainInvariantException;
use App\Models\Currency;
use App\Models\Role;
use App\Models\User;
use App\Modules\Administration\Actions\ApplyQuotationDemoPreset;
use App\Modules\Administration\Actions\BootstrapDemoAccessOperator;
use App\Modules\Administration\Actions\SaveRole;
use App\Modules\Administration\Actions\SyncMemberAccess;
use App\Modules\Administration\Actions\SyncOrganisationUnitMembers;
use App\Modules\Administration\Models\OrganisationUnit;
use App\Modules\Approvals\Actions\ActivateApprovalPolicy;
use App\Modules\Approvals\Actions\CreateApprovalPolicy;
use App\Modules\Approvals\Models\ApprovalPolicy;
use App\Modules\Approvals\Support\ApprovalDecisionKind;
use App\Modules\Inventory\Models\Item;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Actions\Access\GrantQuotationAccess;
use App\Modules\Sales\Actions\Access\RevokeQuotationAccess;
use App\Modules\Sales\Actions\ChangeQuotationAccessUnit;
use App\Modules\Sales\Actions\CreateQuotationDraft;
use App\Modules\Sales\Actions\DecideQuotationApproval;
use App\Modules\Sales\Actions\ReassignQuotationOwner;
use App\Modules\Sales\Actions\RequestQuotationApproval;
use App\Modules\Sales\Actions\UpdateQuotationDraft;
use App\Modules\Sales\Models\QuotationOperationReceipt;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Authority\SalesDevPreview;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as Sales;
use App\Modules\Sales\Support\Quotation\Approval\QuotationApprovalSubject;
use App\Support\CompanyContext;
use App\Support\Demo\QuotationDemoBoundary;
use App\Support\PermissionRegistry;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * The quotation ACCESS demo (ADR 0155, 0156, 0159, 0161) for XYZ Paints, Company 1 only: the synthetic access operator, a set of personas that exercise ownership, units, named shares and
 * revocation, quotations owned by different people, and fictitious two-stage approval routing. Everything goes through the real Actions; the seeder is create-only (every write has a stable
 * key or an existence check), takes the company as a parameter, refuses any company but the designated demo one, and runs only under the local preview (APP_ENV=local AND
 * SALES_DEV_QUOTATION_DUTIES=true); anywhere else it logs one line and writes nothing. Nothing here activates a duty in the shipped manifest or grants repair.
 *
 * The operator: `demo-access-operator@` holds role/access administration (given by the demo administrator through the ordinary Actions) and, through the one reviewed bootstrap Action, the five
 * approved quotation duties. It applies the main login's presets (`sales:demo-preset`) and provisions the personas; ordinary SyncMemberAccess keeps refusing self-change and granting what it
 * does not hold. The existing CRM personas (rep, manager, ...) keep their CRM roles: a full replacement by the operator would be refused, so the new personas below are separate logins.
 *
 *  - demo-quote-manager       preparer role limited to MY_UNITS, member of Head office: reaches quotations placed in that unit.
 *  - demo-quote-governor      governor role (prepare + reassign/share/revoke) limited to MY_UNITS, member of Head office.
 *  - demo-quote-recovery      access manager, company-wide: places quotations in units, reassigns owners, revokes shares.
 *  - demo-quote-delegate      prepares own quotations (OWN) and may share them by name and revoke the shares.
 *  - demo-quote-collaborator  prepares own quotations and those named to them in a share.
 *  - demo-quote-viewer        sees only what is named to them in a share.
 *  - demo-quote-director      second-stage approver (view + approve) for the fictitious two-stage policy below.
 *
 * Quotations (markers in the request keys): SHARED_VIEW (delegate shares VIEW with the viewer), SHARED_EXPIRING (PREPARE share with the collaborator that ends on a fixed date), SHARED_REVOKED
 * (a share given then revoked), IN_UNIT (placed in Head office by the recovery persona, then reassigned to the unit manager by the governor), TRANSFERRED (owner changed from the delegate to the
 * collaborator), OWN_COLLAB (created by the collaborator). Two override drafts show the two-stage approval: STAGE1_DONE (waiting for the director) and BOTH_DONE (ready to issue).
 *
 * FICTITIOUS demo thresholds: the approval engine routes a quotation only on the KIND of concession (discount, override), not on a percentage, so the demo policy is: a discount alone is
 * signed off by the sales lead (the existing "quotation-discounts" policy); a price override (alone or with a discount) needs the sales lead and then the director. The owner's real
 * discount-percentage thresholds replace these when given; the step names below are the only thing to edit. Demo-only, not a business rule.
 */
final class QuotationAccessDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    public const MANAGER = 'demo-quote-manager@erp.x1techs.com';

    public const GOVERNOR = 'demo-quote-governor@erp.x1techs.com';

    public const RECOVERY = 'demo-quote-recovery@erp.x1techs.com';

    public const DELEGATE = 'demo-quote-delegate@erp.x1techs.com';

    public const COLLABORATOR = 'demo-quote-collaborator@erp.x1techs.com';

    public const VIEWER = 'demo-quote-viewer@erp.x1techs.com';

    public const DIRECTOR = 'demo-quote-director@erp.x1techs.com';

    /** Fictitious demo routing (see the class comment); edit the names here, not the engine. */
    public const OVERRIDE_POLICY = 'demo-quotation-override-two-stage';

    public const OVERRIDE_STEPS = ['Sales lead (demo)', 'Director (demo)'];

    /** The fixed end of the expiring share: a fixed instant keeps a rerun a replay. */
    public const SHARE_ENDS = '2030-01-01T00:00:00+00:00';

    /** email => [name, preset, unit code or null] */
    private const PERSONAS = [
        self::MANAGER => ['Demo Quotation Unit Manager', 'manager', 'hq'],
        self::GOVERNOR => ['Demo Quotation Governor', 'governor', 'hq'],
        self::RECOVERY => ['Demo Quotation Access Manager', 'access-manager', null],
        self::DELEGATE => ['Demo Quotation Delegate', 'delegate', null],
        self::COLLABORATOR => ['Demo Quotation Collaborator', 'collaborator', null],
        self::VIEWER => ['Demo Quotation Shared Viewer', 'shared-viewer', null],
    ];

    public function run(): void
    {
        $this->seedFor(app(CompanyContext::class)->id());
    }

    public function seedFor(int $companyId): void
    {
        $this->ensureDemoEnvironment();
        if (! SalesDevPreview::enabled()) {
            Log::info('Quotation access demo skipped: needs APP_ENV=local and SALES_DEV_QUOTATION_DUTIES=true (the access, share and approve duties stay STAGED everywhere else).');

            return;
        }
        QuotationDemoBoundary::assertCompany($companyId);
        // Idempotent: makes sure the registry reflects the container's (preview) manifest even if it was resolved earlier in this process.
        app(SalesDutyManifest::class)->registerWith(app(PermissionRegistry::class));
        app(PermissionRegistry::class)->registerForTeam($companyId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($companyId);
        $admin = User::query()->where('company_id', $companyId)->where('email', QuotationDemoBoundary::MAIN_EMAIL)->firstOrFail();
        $alpha = Party::query()->where('code', 'cus-alpha')->first();
        $metro = Party::query()->where('code', 'cus-metro')->first();
        $pkr = Currency::query()->where('code', 'PKR')->first();
        if ($alpha === null || $metro === null || $pkr === null) {
            Log::info('Quotation access demo skipped: the demo customers or currency do not exist yet (PartiesDemoSeeder).');

            return;
        }

        // The login works the approval scenarios, so it holds these duties only while this runs: what the run adds is taken away again (issue #100), so a rerun or another seeder
        // never leaves a persisted direct grant behind. A grant the login already held directly is left exactly as it was.
        $duties = [Sales::QUOTATION_VIEW, Sales::QUOTATION_PREPARE, Sales::QUOTATION_DISCOUNT, Sales::QUOTATION_PRICE_OVERRIDE];
        foreach ($duties as $duty) {
            Permission::findOrCreate($duty, 'web');
        }
        $heldBefore = $admin->getDirectPermissions()->pluck('name')->all();
        $admin->givePermissionTo($duties);
        $shipped = app(SalesDutyManifest::class);
        $staged = array_values(array_filter($duties, fn (string $duty): bool => ! $shipped->isActive($duty)));
        app()->instance(SalesDutyManifest::class, $staged === [] ? $shipped : $shipped->withActivated($staged));
        try {
            DB::transaction(function () use ($admin, $alpha, $metro, $pkr): void {
                $operator = $this->operator($admin);
                $people = $this->personas($admin, $operator);
                $this->enterMainUserIntoHeadOffice($admin);
                $this->accessScenarios($people, $alpha, $metro, $pkr);
                $this->approvalTiers($admin, $metro, $pkr);
            });
        } finally {
            app()->instance(SalesDutyManifest::class, $shipped);
            $added = array_values(array_diff($duties, $heldBefore));
            if ($added !== []) {
                $admin->revokePermissionTo($added);
            }
        }
    }

    private function operator(User $admin): User
    {
        $operator = $this->demoMember(QuotationDemoBoundary::OPERATOR_EMAIL, 'Demo Access Operator');
        if (! $operator->roles()->exists() && ! $operator->permissions()->exists()) {
            $role = Role::query()->where('team_id', $admin->company_id)->where('name', 'Demo access operator')->first()
                ?? app(SaveRole::class)(null, [
                    'name' => 'Demo access operator', 'description' => 'Synthetic demo login that applies quotation presets. Holds role and access administration only; its quotation duties come from the reviewed bootstrap. Demo only.',
                    'permissions' => ['administration.access.assign', 'administration.role.manage'], 'reason' => 'Demo: the synthetic access operator administers access (ADR 0155).',
                ], $admin);
            app(SyncMemberAccess::class)($operator, ['role_ids' => [(int) $role->id], 'direct_permissions' => [], 'scope_limits' => [], 'reason' => 'Demo: the synthetic access operator administers access (ADR 0155).'], $admin);
        }
        app(BootstrapDemoAccessOperator::class)($operator->refresh());

        return $operator->refresh();
    }

    /** @return array<string, User> email => user */
    private function personas(User $admin, User $operator): array
    {
        $apply = app(ApplyQuotationDemoPreset::class);
        $people = [];
        foreach (self::PERSONAS as $email => [$name, $preset, $unit]) {
            $person = $this->demoMember($email, $name);
            $apply->provision($person, $preset, $operator);
            if ($unit !== null) {
                $this->place($admin, $person, $unit);
            }
            $people[$email] = $person->refresh();
        }
        // The director decides the second stage. Same pattern as the existing approver persona: the view and approve rows are given directly (demo data); nothing reaches the shipped manifest.
        $director = $this->demoMember(self::DIRECTOR, 'Demo Quotation Director');
        foreach ([Sales::QUOTATION_VIEW, Sales::QUOTATION_APPROVE] as $duty) {
            Permission::findOrCreate($duty, 'web');
        }
        if (! $director->permissions()->exists() && ! $director->roles()->exists()) {
            $director->givePermissionTo([Sales::QUOTATION_VIEW, Sales::QUOTATION_APPROVE]);
        }
        $people[self::DIRECTOR] = $director->refresh();

        return $people;
    }

    /** Belonging to a unit is not access; it only matters to MY_UNITS assignments. Added only while the person belongs to no unit. */
    private function place(User $admin, User $person, string $unitCode): void
    {
        $unit = OrganisationUnit::query()->where('code', $unitCode)->first();
        if ($unit === null || OrganisationUnit::query()->whereHas('members', fn ($q) => $q->where('users.id', $person->id))->exists()) {
            return;
        }
        app(SyncOrganisationUnitMembers::class)($unit, [...$unit->members()->pluck('users.id')->all(), $person->id], $admin);
    }

    /** So the main login's "manager" and "governor" presets reach the Head office quotations. Only while it belongs to no unit; it grants no access by itself. */
    private function enterMainUserIntoHeadOffice(User $admin): void
    {
        $this->place($admin, $admin, 'hq');
    }

    /** Names the failing step, so a refusal in a demo scenario is traceable. */
    private function step(string $label, \Closure $do): mixed
    {
        try {
            return $do();
        } catch (DomainInvariantException $refusal) {
            throw new DomainInvariantException("Access demo step [{$label}] refused: ".$refusal->getMessage(), $refusal->field ?? null, $refusal->reasonCode ?? null);
        }
    }

    /** @param  array<string, User>  $people */
    private function accessScenarios(array $people, Party $alpha, Party $metro, Currency $pkr): void
    {
        $delegate = $people[self::DELEGATE];
        $viewer = $people[self::VIEWER];
        $collaborator = $people[self::COLLABORATOR];
        $recovery = $people[self::RECOVERY];
        $governor = $people[self::GOVERNOR];
        $manager = $people[self::MANAGER];
        $hq = OrganisationUnit::query()->where('code', 'hq')->first();
        $today = now()->format('Y-m-d');
        $header = fn (Party $to): array => ['recipient_party_id' => $to->id, 'currency_id' => $pkr->id, 'pricing_date' => $today, 'valid_through_local_date' => now()->addMonths(2)->format('Y-m-d')];
        $line = fn (string $text, string $price): array => ['source_kind' => 'MANUAL', 'description' => $text, 'manual_measure' => 'job', 'offered_quantity' => '1', 'unit_price' => $price];

        // A new family starts at ownership generation 1 and access generation 0; every later write is chained through the generations the previous outcome reported (a replay reports the originals).
        $draft = function (User $by, string $marker, Party $to, string $text, string $price) use ($header, $line): int {
            $by->refresh(); // a share or revocation advances the actor's authorization epoch; never act on a stale copy
            // A rerun must not call the creator's Actions again: once a quotation was reassigned or placed in a unit the creator no longer reaches it, and a replay is checked against current reach.
            $done = QuotationOperationReceipt::query()->where('actor_id', $by->id)->where('request_key', "demo-acc-{$marker}-lines")->first();
            if ($done !== null) {
                return (int) $done->family_id;
            }
            $created = $this->step("{$marker} create by {$by->email}", fn () => app(CreateQuotationDraft::class)($by, "demo-acc-{$marker}", $header($to)));
            $this->step("{$marker} lines", fn () => app(UpdateQuotationDraft::class)($by, $created->revisionId, $created->inputGeneration, "demo-acc-{$marker}-lines", ['add_lines' => [$line($text, $price)]]));

            return $created->familyId;
        };
        $share = fn (User $by, int $family, User $to, string $level, array $gen, string $marker, string $why, ?string $ends = null) => app(GrantQuotationAccess::class)($by, $family, $to->id, $level, $gen[0], $gen[1], "demo-acc-{$marker}", $why, null, $ends);

        // SHARED_VIEW: the delegate shares one of their own quotations, read-only, with a person who otherwise sees nothing.
        $shared = $draft($delegate, 'shared-view', $alpha, 'Showroom repaint (shared for review)', '240000.00');
        $this->step('shared-view grant', fn () => $share($delegate, $shared, $viewer, 'VIEW', [1, 0], 'shared-view-grant', 'Demo: shared read-only for review.'));

        // SHARED_EXPIRING: a PREPARE share that ends on a fixed date.
        $expiring = $draft($delegate, 'shared-expiring', $metro, 'Boundary wall coating (collaborator may edit)', '310000.00');
        $this->step('shared-expiring grant', fn () => $share($delegate, $expiring, $collaborator, 'PREPARE', [1, 0], 'shared-expiring-grant', 'Demo: collaborator may edit until the end date.', self::SHARE_ENDS));

        // IN_UNIT: placed in Head office by the access manager (a unit-less family is reached only by owner or company-wide scope), then handed to the unit manager by the governor.
        if ($hq !== null) {
            $unit = $draft($delegate, 'in-unit', $metro, 'Plant room floor coating (Head office)', '520000.00');
            $placed = $this->step('in-unit place', fn () => app(ChangeQuotationAccessUnit::class)($recovery, $unit, $hq->id, 1, 0, 'demo-acc-in-unit-place', 'Demo: belongs to Head office.'));
            $this->step('in-unit reassign', fn () => app(ReassignQuotationOwner::class)($governor, $unit, $manager->id, $placed->ownershipGeneration, $placed->accessGeneration, 'demo-acc-in-unit-reassign', 'Demo: the unit manager takes over this quotation.'));
        }

        // TRANSFERRED: responsibility moves from one person to another; the previous owner stays in the history and loses OWN reach.
        $moved = $draft($delegate, 'transferred', $alpha, 'Warehouse racking paint (new owner)', '180000.00');
        $this->step('transferred reassign', fn () => app(ReassignQuotationOwner::class)($recovery, $moved, $collaborator->id, 1, 0, 'demo-acc-transferred-reassign', 'Demo: the delegate is off the project; the collaborator is now responsible.'));

        // SHARED_REVOKED: given, then taken back. The history keeps both.
        $revoked = $draft($delegate, 'shared-revoked', $alpha, 'Car park line marking (share withdrawn)', '95000.00');
        $given = $this->step('shared-revoked grant', fn () => $share($delegate, $revoked, $viewer, 'VIEW', [1, 0], 'shared-revoked-grant', 'Demo: shared for a second opinion.'));
        $this->step('shared-revoked revoke', fn () => app(RevokeQuotationAccess::class)($delegate, $revoked, (int) $given->grantId, $given->ownershipGeneration, $given->accessGeneration, 'demo-acc-shared-revoked-revoke', 'Demo: the second opinion is no longer needed.'));

        // OWN_COLLAB: simply the collaborator's own quotation (not visible to the delegate or the viewer).
        $draft($collaborator, 'own-collab', $metro, 'Office partition painting', '75000.00');
    }

    /**
     * The fictitious two-stage routing for price overrides (see the class comment), created only when absent, and two scenarios decided by DIFFERENT people: the sales lead, then the director.
     * Needs the approvals demo (the lead persona, the service item and the declared demo tax) first; otherwise it writes nothing.
     */
    private function approvalTiers(User $admin, Party $metro, Currency $pkr): void
    {
        $lead = User::query()->where('email', QuotationApprovalsDemoSeeder::APPROVER)->first();
        $director = User::query()->where('email', self::DIRECTOR)->first();
        $service = Item::query()->where('code', 'demo-site-application')->first();
        if ($lead === null || $director === null || $service === null || ! DB::table('approval_subject_settings')->where('subject_type', QuotationApprovalSubject::CODE)->exists()) {
            Log::info('Quotation access demo: the two-stage approval scenarios were skipped (the approvals demo has not run).');

            return;
        }
        if (! ApprovalPolicy::query()->where('subject_type', QuotationApprovalSubject::CODE)->where('code', self::OVERRIDE_POLICY)->exists()) {
            $policy = app(CreateApprovalPolicy::class)([
                'subject_type' => QuotationApprovalSubject::CODE, 'code' => self::OVERRIDE_POLICY, 'name' => 'DEMO ONLY: price override needs sales lead, then director', 'priority' => 10,
                'reason' => 'Demo: FICTITIOUS routing - the real thresholds are the owner\'s to supply. The engine routes on the kind of concession, not on a percentage.',
                'conditions' => [['fact' => 'concessions', 'operator' => 'IN', 'value' => ['OVERRIDE', 'DISCOUNT+OVERRIDE']]],
                'steps' => [
                    ['name' => self::OVERRIDE_STEPS[0], 'stage' => 1, 'required_approvals' => 1, 'use_authority_limit' => false],
                    ['name' => self::OVERRIDE_STEPS[1], 'stage' => 2, 'required_approvals' => 1, 'use_authority_limit' => false],
                ],
            ], $admin);
            app(ActivateApprovalPolicy::class)($policy, $admin);
        }
        $today = now()->format('Y-m-d');
        $make = function (string $marker, string $net) use ($admin, $metro, $pkr, $service, $today): QuotationRevision {
            $created = app(CreateQuotationDraft::class)($admin, "demo-acc-{$marker}", ['recipient_party_id' => $metro->id, 'currency_id' => $pkr->id, 'pricing_date' => $today, 'tax_estimate_date' => $today, 'valid_through_local_date' => now()->addMonths(2)->format('Y-m-d')]);
            $generation = app(UpdateQuotationDraft::class)($admin, $created->revisionId, $created->inputGeneration, "demo-acc-{$marker}-lines", ['add_lines' => [
                ['source_kind' => 'SERVICE_ITEM', 'item_id' => $service->id, 'offered_quantity' => '2', 'unit_price' => '25000.0000', 'net_override' => ['amount' => $net, 'reason' => 'Demo: a deep reduction that needs the director as well.']],
            ]])->inputGeneration;
            $revision = QuotationRevision::query()->findOrFail($created->revisionId);
            $revision->input_generation = $generation;
            app(RequestQuotationApproval::class)($admin, $revision->id, $generation, "demo-acc-{$marker}-request");

            return $revision;
        };
        $decide = fn (User $by, QuotationRevision $draft, string $marker, string $why) => app(DecideQuotationApproval::class)($by, $draft->id, $draft->input_generation, ApprovalDecisionKind::Approved, $why, "demo-acc-{$marker}-decide");

        $first = $this->step('tier stage1 make', fn () => $make('stage1-done', '42000'));
        $this->step('tier stage1 lead', fn () => $decide($lead, $first, 'stage1-done-lead', 'Demo: the sales lead agrees; the director still has to decide.'));

        $both = $this->step('tier both make', fn () => $make('both-done', '41000'));
        $this->step('tier both lead', fn () => $decide($lead, $both, 'both-done-lead', 'Demo: the sales lead agrees.'));
        $this->step('tier both director', fn () => $decide($director, $both, 'both-done-director', 'Demo: the director agrees.'));
    }
}
