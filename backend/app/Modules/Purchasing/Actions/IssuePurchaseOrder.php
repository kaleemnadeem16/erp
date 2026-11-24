<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Approvals\Actions\RequireApproval;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\ItemQuantityRules;
use App\Modules\Numbering\Services\NumberingService;
use App\Modules\Parties\Models\SupplierAccount;
use App\Modules\Purchasing\Models\ItemSupplier;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Numbering\PurchaseOrderNumbering;
use App\Modules\Purchasing\Support\PurchaseOrderAccess;
use App\Modules\Purchasing\Support\PurchaseOrderApprovalSubject;
use App\Modules\Purchasing\Support\PurchaseOrderStatus;
use App\Support\Quantity\QuantitySign;
use App\Support\Scope\DecisionActor;
use App\Support\Scope\RecordScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class IssuePurchaseOrder
{
    public function __construct(
        private readonly NumberingService $numbering,
        private readonly ResolvePurchasingSettings $resolveSettings,
        private readonly RequireApproval $requireApproval,
    ) {}

    public function __invoke(PurchaseOrder $order, User $actor): PurchaseOrder
    {
        $canCommitCutoverOrder = $order->inventory_cutover_id !== null
            && $actor->can('purchasing.cutover.commit');

        if (! $actor->can('purchasing.purchase-order.issue') && ! $canCommitCutoverOrder) {
            throw new DomainInvariantException('The actor is not permitted to issue a purchase order.', 'issued_by');
        }

        return DB::transaction(function () use ($order, $actor): PurchaseOrder {
            // The early check only fails fast. The decision is taken from the stored actor, under the company access barrier, before any row lock.
            $actor = DecisionActor::fresh($actor, 'issue a purchase order', 'issued_by');
            // An open-PO cutover order may also be issued by the unrestricted cutover workflow; every other path needs the issue duty's reach.
            $viaCutover = $actor->can('purchasing.cutover.commit') && app(RecordScope::class)->resolve($actor, 'purchasing.cutover.commit')->isAll();
            $reachedByDuty = $actor->can('purchasing.purchase-order.issue')
                && PurchaseOrderAccess::make()->scopeForDuty(PurchaseOrder::query(), $actor, 'purchasing.purchase-order.issue')->whereKey($order->id)->exists();
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
            if (! $reachedByDuty && ! ($viaCutover && $order->inventory_cutover_id !== null)) {
                // Unreachable reads as absent; a reachable order the person lacks the duty for is a plain refusal below.
                if ($actor->can('purchasing.purchase-order.issue')) {
                    throw (new ModelNotFoundException)->setModel(PurchaseOrder::class, [$order->id]);
                }
            }
            if (! $actor->can('purchasing.purchase-order.issue') && ! ($order->inventory_cutover_id !== null && $actor->can('purchasing.cutover.commit'))) {
                throw new DomainInvariantException('The actor is not permitted to issue a purchase order.', 'issued_by');
            }
            if ($order->status !== PurchaseOrderStatus::Draft || ! $order->lines()->exists()) {
                throw new DomainInvariantException('Only a non-empty draft purchase order can be issued.', 'status');
            }
            // A migrated open order (cutover) was decided in the legacy system; a new order needs the company's approval.
            if ($order->inventory_cutover_id === null) {
                ($this->requireApproval)(PurchaseOrderApprovalSubject::CODE, $order->id);
            }
            $this->requireShipToStillUsable($order);
            $settings = ($this->resolveSettings)();
            foreach ($order->lines()->with('item')->lockForUpdate()->get() as $line) {
                if ($line->offer_snapshot !== null && (($line->offer_snapshot['supplier_account_id'] ?? null) !== $order->supplier_account_id
                    || ($line->offer_snapshot['currency_id'] ?? null) !== $order->currency_id)) {
                    throw new DomainInvariantException('Supplier/currency changed after offer resolution; resave the lines before issuing.', 'lines');
                }
                $supplierItem = ItemSupplier::query()->where('item_id', $line->item_id)
                    ->where('supplier_account_id', $order->supplier_account_id)->where('active', true)->first();
                // ADR 0142: the Item's stock increment in force when the order becomes official is pinned on the line, so a later Item edit
                // cannot reinterpret the ordered quantity or the over-receipt ceiling. The saved stock quantity must still respect it.
                $stockIncrement = $line->item->quantity_precision;
                if ($stockIncrement !== null) {
                    ItemQuantityRules::withIncrement($line->item)->represent($line->ordered_stock_qty, QuantitySign::Positive, 'lines');
                }
                $line->update([
                    'stock_increment' => $stockIncrement,
                    'item_code' => $line->item->code,
                    'supplier_item_code' => $line->supplier_offer_id ? $line->supplier_item_code : $supplierItem?->supplier_item_code,
                    'over_receipt_mode' => $line->over_receipt_mode ?? $settings->over_receipt_mode,
                    'over_receipt_tolerance_percent' => $line->over_receipt_tolerance_percent ?? $settings->over_receipt_tolerance_percent,
                ]);
            }
            $issuedAt = CarbonImmutable::now();
            // The number is taken at the moment the order becomes official, by the central numbering framework (ADR 0079).
            $number = $this->numbering->allocate(PurchaseOrderNumbering::contextFor($order, $issuedAt, $actor->id))->number;
            // The terms in force are frozen with the order: its own override, or else the supplier's default as it stands now, and where they came from.
            $terms = $order->payment_terms_days !== null
                ? [$order->payment_terms_days, 'PURCHASE_ORDER']
                : [SupplierAccount::query()->whereKey($order->supplier_account_id)->value('payment_terms_days'), 'SUPPLIER_DEFAULT'];
            $order->update(['number' => $number, 'status' => PurchaseOrderStatus::Issued, 'issued_at' => $issuedAt,
                'payment_terms_days' => $terms[0], 'payment_terms_source' => $terms[0] === null ? null : $terms[1]]);

            return $order->refresh()->load('lines');
        });
    }

    /** The delivery point named on the draft must still be a place the company can receive into when the order becomes official. */
    private function requireShipToStillUsable(PurchaseOrder $order): void
    {
        if ($order->ship_to_warehouse_id !== null && ! Warehouse::query()->whereKey($order->ship_to_warehouse_id)->where('active', true)->exists()) {
            throw new DomainInvariantException('The ship-to warehouse is no longer active; choose another before issuing.', 'ship_to_warehouse_id');
        }
        if ($order->ship_to_location_id !== null && ! Location::query()->whereKey($order->ship_to_location_id)->where('active', true)->exists()) {
            throw new DomainInvariantException('The ship-to location is no longer active; choose another before issuing.', 'ship_to_location_id');
        }
    }
}
