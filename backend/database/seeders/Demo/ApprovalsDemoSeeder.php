<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Currency;
use App\Models\User;
use App\Modules\Approvals\Actions\ActivateApprovalPolicy;
use App\Modules\Approvals\Actions\CreateApprovalAuthority;
use App\Modules\Approvals\Actions\CreateApprovalDelegation;
use App\Modules\Approvals\Actions\CreateApprovalPolicy;
use App\Modules\Approvals\Actions\DecideApproval;
use App\Modules\Approvals\Actions\SaveApprovalSubjectSetting;
use App\Modules\Approvals\Actions\WithdrawApproval;
use App\Modules\Approvals\Models\ApprovalAuthority;
use App\Modules\Approvals\Models\ApprovalDelegation;
use App\Modules\Approvals\Models\ApprovalPolicy;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Support\ApprovalDecisionKind;
use App\Modules\Approvals\Support\ApprovalMode;
use App\Modules\Approvals\Support\ApprovalPolicyStatus;
use App\Modules\Approvals\Support\ApprovalRequestStatus;
use App\Modules\Estimating\Actions\ActivateMaterialVariancePolicy;
use App\Modules\Estimating\Actions\CreateMaterialVariancePolicy;
use App\Modules\Estimating\Models\MaterialVariancePolicy;
use App\Modules\Estimating\Support\MaterialVariancePolicyStatus;
use App\Modules\Estimating\Support\VarianceApprovalAvailability;
use App\Modules\Estimating\Support\VarianceScopeType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Parties\Models\SupplierAccount;
use App\Modules\Purchasing\Actions\CreatePurchaseOrder;
use App\Modules\Purchasing\Actions\IssuePurchaseOrder;
use App\Modules\Purchasing\Actions\SubmitPurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Support\PermissionRegistry;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;

/**
 * The shared approval engine (ADR 0070), demonstrated as a company would actually configure it. Approvals are switched
 * on for purchase orders and for material variance, with two amount-banded purchase-order policies:
 *
 *   - `po-over-100k` (100,000 and over): one Purchasing-manager approval, within the approver's limit.
 *   - `po-over-500k` (500,000 and over, higher priority): a manager (within their limit) and then two distinct Finance
 *     approvers (each within their limit) in a later stage.
 *
 * Three sign-in personas (demo password) show the roles: a Buyer who submits, a Purchasing Manager who may approve up to
 * 300,000, and a Finance Controller with no ceiling. The manager has delegated to the Buyer for a week — a delegate acts
 * within the manager's authority, and, since the Buyer submitted the order, may not approve their own submission.
 *
 * Orders, each carrying its state through the app's own Actions on the demo supplier:
 *   A. 250,000 — submitted, waiting for the manager (the demo login can approve it live). Once approved, re-seeding submits a
 *      fresh one so the demo always has a request to decide.
 *   B. 650,000 — the Finance Controller approved the manager stage; the Finance stage has one of two approvals.
 *   C. 180,000 — approved, then issued.
 *   D. 120,000 — rejected, with the reason.
 *   E. 210,000 — withdrawn by the Buyer to be corrected.
 *
 * It also lets the demo pigment's variance rule use the `APPROVAL_REQUIRED` effect (planning more than double the standard),
 * with a "Variance sign-off" approval policy, so the Material Preview shows which approval such a plan would need.
 *
 * Every order is recognised by a note marker and never recreated; policies, limits and the delegation are reconciled.
 */
final class ApprovalsDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    private const PO_APPROVE = 'purchasing.purchase-order.approve';

    private User $admin;

    private User $buyer;

    private User $manager;

    private User $finance;

    public function run(): void
    {
        $this->ensureDemoEnvironment();
        app(PermissionRegistry::class)->registerForTeam(1);

        $this->admin = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        $this->buyer = $this->persona('demo-buyer@erp.x1techs.com', 'Demo Buyer', ['purchasing.purchase-order.view', 'purchasing.purchase-order.create', 'purchasing.purchase-order.submit', 'purchasing.commercial-cost.view', 'purchasing.item-supplier.view']);
        $this->manager = $this->persona('demo-manager@erp.x1techs.com', 'Demo Purchasing Manager', ['purchasing.purchase-order.view', self::PO_APPROVE, 'purchasing.commercial-cost.view']);
        $this->finance = $this->persona('demo-finance@erp.x1techs.com', 'Demo Finance Controller', ['purchasing.purchase-order.view', self::PO_APPROVE, 'purchasing.purchase-order.issue', 'purchasing.commercial-cost.view']);

        $save = app(SaveApprovalSubjectSetting::class);
        $save('PURCHASE_ORDER', ApprovalMode::Policy, $this->admin->id);
        $save('MATERIAL_VARIANCE', ApprovalMode::Policy, $this->admin->id);

        $this->policies();
        $this->authorities();
        $this->delegation();
        $this->orders();
        $this->varianceApproval();
    }

    /** @param  list<string>  $permissions */
    private function persona(string $email, string $name, array $permissions): User
    {
        $user = User::query()->updateOrCreate(['email' => $email], ['name' => $name, 'password' => config('demo.user.password')]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->syncPermissions($permissions);

        return $user;
    }

    private function policies(): void
    {
        $this->ensurePolicy('PURCHASE_ORDER', 'po-over-100k', 'Orders of 100,000 and over', 10, [['amount_base', 'GTE', '100000']], [
            ['name' => 'Purchasing manager', 'stage' => 1, 'required_approvals' => 1, 'use_authority_limit' => true],
        ]);
        $this->ensurePolicy('PURCHASE_ORDER', 'po-over-500k', 'Orders of 500,000 and over', 20, [['amount_base', 'GTE', '500000']], [
            ['name' => 'Purchasing manager', 'stage' => 1, 'required_approvals' => 1, 'use_authority_limit' => true],
            ['name' => 'Finance', 'stage' => 2, 'required_approvals' => 2, 'use_authority_limit' => true, 'expires_after_hours' => 48, 'on_expiry' => 'FLAG_OVERDUE'],
        ]);
        $this->ensurePolicy('MATERIAL_VARIANCE', 'variance-signoff', 'Variance sign-off', 0, [], [
            ['name' => 'Estimating lead', 'stage' => 1, 'required_approvals' => 1, 'permission' => 'estimating.material_variance.approve'],
        ]);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $conditions
     * @param  list<array<string, mixed>>  $steps
     */
    private function ensurePolicy(string $subject, string $code, string $name, int $priority, array $conditions, array $steps): void
    {
        if (ApprovalPolicy::query()->where('subject_type', $subject)->where('code', $code)->where('status', ApprovalPolicyStatus::Active)->exists()) {
            return;
        }
        $policy = app(CreateApprovalPolicy::class)([
            'subject_type' => $subject, 'code' => $code, 'name' => $name, 'priority' => $priority, 'reason' => 'Demo: an example approval policy — a real company sets its own.',
            'conditions' => array_map(fn (array $c): array => ['fact' => $c[0], 'operator' => $c[1], 'value' => $c[2]], $conditions), 'steps' => $steps,
        ], $this->admin->id);
        app(ActivateApprovalPolicy::class)($policy);
    }

    private function authorities(): void
    {
        foreach ([[$this->manager, '300000', false], [$this->finance, null, true], [$this->admin, null, true]] as [$user, $ceiling, $unlimited]) {
            if (ApprovalAuthority::query()->where('user_id', $user->id)->where('subject_type', 'PURCHASE_ORDER')->whereNull('revoked_at')->exists()) {
                continue;
            }
            app(CreateApprovalAuthority::class)([
                'user_id' => $user->id, 'subject_type' => 'PURCHASE_ORDER', 'unlimited' => $unlimited, 'ceiling_base_amount' => $ceiling,
                'reason' => $unlimited ? 'Demo: no ceiling' : 'Demo: Purchasing manager approves up to 300,000', 'valid_from' => null, 'valid_to' => null,
            ], $this->admin->id);
        }
    }

    private function delegation(): void
    {
        if (ApprovalDelegation::query()->where('delegator_id', $this->manager->id)->where('delegate_id', $this->buyer->id)->whereNull('revoked_at')->where('valid_to', '>=', today()->toDateString())->exists()) {
            return;
        }
        app(CreateApprovalDelegation::class)([
            'delegator_id' => $this->manager->id, 'delegate_id' => $this->buyer->id, 'subject_type' => 'PURCHASE_ORDER',
            'valid_from' => today()->toDateString(), 'valid_to' => today()->addDays(7)->toDateString(), 'reason' => 'Demo: manager away this week',
        ], $this->manager->id);
    }

    private function orders(): void
    {
        $decide = app(DecideApproval::class);

        $waiting = function (PurchaseOrder $order): void {
            app(SubmitPurchaseOrder::class)($order, $this->buyer);
        };
        $this->order('A', '250000', $waiting);
        $this->keepOneOrderWaiting($waiting);

        $this->order('B', '650000', function (PurchaseOrder $order) use ($decide): void {
            $request = app(SubmitPurchaseOrder::class)($order, $this->buyer);
            // The manager's own ceiling (300,000) does not reach 650,000, so the first stage is decided by Finance.
            $request = $decide($request, $this->finance, ApprovalDecisionKind::Approved, 'Within budget; supplier terms are as agreed.');
            $decide($request, $this->admin, ApprovalDecisionKind::Approved, 'First of the two Finance approvals.');
        });

        $this->order('C', '180000', function (PurchaseOrder $order) use ($decide): void {
            $request = app(SubmitPurchaseOrder::class)($order, $this->buyer);
            $decide($request, $this->manager, ApprovalDecisionKind::Approved, 'Approved: matches the quotation.');
            app(IssuePurchaseOrder::class)($order->refresh(), $this->admin);
        });

        $this->order('D', '120000', function (PurchaseOrder $order) use ($decide): void {
            $request = app(SubmitPurchaseOrder::class)($order, $this->buyer);
            $decide($request, $this->manager, ApprovalDecisionKind::Rejected, 'Price is well above the last three orders; renegotiate first.');
        });

        $this->order('E', '210000', function (PurchaseOrder $order): void {
            $request = app(SubmitPurchaseOrder::class)($order, $this->buyer);
            app(WithdrawApproval::class)($request, $this->buyer, 'Quantity needs correcting before this is approved.');
        });
    }

    /**
     * The demo promises an order waiting for the manager that the demo login can approve live. Approving it (which is the
     * point of the demo) uses it up, so when none is waiting any more a fresh one — same amount, next marker — is
     * submitted. While one is waiting nothing is created, so re-running changes nothing.
     */
    private function keepOneOrderWaiting(callable $scenario): void
    {
        $orders = PurchaseOrder::query()->where('notes', 'like', '%DEMO_APPROVAL_A%')->get();
        $stillWaiting = ApprovalRequest::query()
            ->where('subject_type', 'PURCHASE_ORDER')
            ->whereIn('subject_id', $orders->modelKeys())
            ->where('status', ApprovalRequestStatus::Pending)
            ->exists();
        if (! $stillWaiting) {
            $this->order('A'.($orders->count() + 1), '250000', $scenario);
        }
    }

    /** Creates one demo order worth `$amount` (one line: 1,000 units at amount / 1,000) and applies its scenario, once. */
    private function order(string $marker, string $amount, callable $scenario): void
    {
        $note = "DEMO_APPROVAL_{$marker}";
        if (PurchaseOrder::query()->where('notes', 'like', "%{$note}%")->exists()) {
            return;
        }

        $item = Item::query()->where('code', 'demo-pig-blue')->firstOrFail();
        $supplier = SupplierAccount::query()->where('code', 'sup-punjab')->firstOrFail();
        $currency = Currency::query()->where('code', 'PKR')->firstOrFail();
        $date = now()->format('Y-m-d');

        $order = app(CreatePurchaseOrder::class)([
            'supplier_account_id' => $supplier->getKey(), 'currency_id' => $currency->getKey(),
            'fx_rate' => '1.000000000', 'fx_rate_date' => $date, 'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual', 'order_date' => $date,
            'notes' => "Demo approval scenario {$marker} ({$note}): a bulk pigment order worth {$amount}.",
            'lines' => [[
                'line_number' => 1, 'item_id' => $item->getKey(), 'description' => $item->name, 'ordered_qty' => '1000.0000',
                'purchase_uom_id' => $item->stock_uom_id, 'unit_price' => number_format((float) $amount / 1000, 4, '.', ''),
            ]],
        ]);
        $scenario($order);
    }

    /** Lets the demo pigment's rule use APPROVAL_REQUIRED for a plan of more than double the standard. */
    private function varianceApproval(): void
    {
        if (! app(VarianceApprovalAvailability::class)->enabled()) {
            return;
        }
        $pigment = Item::query()->where('code', 'demo-pig-blue')->first();
        $active = $pigment === null ? null : MaterialVariancePolicy::query()->with('bands')
            ->where('scope_type', VarianceScopeType::Item)->where('scope_id', $pigment->id)->where('status', MaterialVariancePolicyStatus::Active)->first();
        if ($active === null || $active->bands->contains(fn ($band): bool => $band->effect->value === 'APPROVAL_REQUIRED')) {
            return;
        }

        $bands = $active->bands->map(fn ($band): array => [
            'direction' => $band->direction->value, 'measure' => $band->measure->value, 'threshold' => $band->threshold,
            'uom_id' => $band->uom_id, 'effect' => $band->effect->value,
        ])->all();
        $bands[] = ['direction' => 'OVER', 'measure' => 'PERCENT', 'threshold' => '100', 'uom_id' => null, 'effect' => 'APPROVAL_REQUIRED'];

        $next = app(CreateMaterialVariancePolicy::class)([
            'scope_type' => VarianceScopeType::Item->value, 'scope_id' => $pigment->id, 'minimum_effect' => $active->minimum_effect?->value,
            'reason' => 'Demo: planning more than double the pigment needs approval, not just a reason.', 'bands' => $bands,
        ], $this->admin->id);
        app(ActivateMaterialVariancePolicy::class)($next);
    }
}
