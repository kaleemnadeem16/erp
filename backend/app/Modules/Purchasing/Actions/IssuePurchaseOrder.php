<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Approvals\Actions\RequireApproval;
use App\Modules\Numbering\Services\NumberingService;
use App\Modules\Purchasing\Models\ItemSupplier;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Numbering\PurchaseOrderNumbering;
use App\Modules\Purchasing\Support\PurchaseOrderApprovalSubject;
use App\Modules\Purchasing\Support\PurchaseOrderStatus;
use Carbon\CarbonImmutable;
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
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->status !== PurchaseOrderStatus::Draft || ! $order->lines()->exists()) {
                throw new DomainInvariantException('Only a non-empty draft purchase order can be issued.', 'status');
            }
            // A migrated open order (cutover) was decided in the legacy system; a new order needs the company's approval.
            if ($order->inventory_cutover_id === null) {
                ($this->requireApproval)(PurchaseOrderApprovalSubject::CODE, $order->id);
            }
            $settings = ($this->resolveSettings)();
            foreach ($order->lines()->with('item')->lockForUpdate()->get() as $line) {
                $supplierItem = ItemSupplier::query()->where('item_id', $line->item_id)
                    ->where('supplier_account_id', $order->supplier_account_id)->where('active', true)->first();
                $line->update([
                    'item_code' => $line->item->code,
                    'supplier_item_code' => $supplierItem?->supplier_item_code,
                    'over_receipt_mode' => $line->over_receipt_mode ?? $settings->over_receipt_mode,
                    'over_receipt_tolerance_percent' => $line->over_receipt_tolerance_percent ?? $settings->over_receipt_tolerance_percent,
                ]);
            }
            $issuedAt = CarbonImmutable::now();
            // The number is taken at the moment the order becomes official, by the central numbering framework (ADR 0079).
            $number = $this->numbering->allocate(PurchaseOrderNumbering::contextFor($order, $issuedAt, $actor->id))->number;
            $order->update(['number' => $number, 'status' => PurchaseOrderStatus::Issued, 'issued_at' => $issuedAt]);

            return $order->refresh()->load('lines');
        });
    }
}
