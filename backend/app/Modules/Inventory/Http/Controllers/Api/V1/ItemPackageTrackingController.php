<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Actions\ActivateGroupedPackageTracking;
use App\Modules\Inventory\Actions\PreviewGroupedActivation;
use App\Modules\Inventory\Http\Requests\PackageStock\ActivateItemPackageTrackingRequest;
use App\Modules\Inventory\Http\Requests\PackageStock\PreviewItemPackageActivationRequest;
use App\Modules\Inventory\Http\Requests\PackageStock\ShowItemPackageTrackingRequest;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemPackaging;
use App\Modules\Inventory\Support\PackageTrackedLevels;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ItemPackageTrackingController extends Controller
{
    /** The item's package-tracking state, its packages (for the opening count and tracked levels), and what the caller may do. */
    public function show(ShowItemPackageTrackingRequest $request, Item $item): JsonResponse
    {
        $tracked = PackageTrackedLevels::for($item->id);
        $packagings = ItemPackaging::query()->where('item_id', $item->id)->orderBy('stock_quantity')->get(['id', 'code', 'name', 'stock_quantity', 'inner_packaging_id', 'active']);
        $activation = $item->package_tracking_activation_id === null ? null
            : DB::table('inventory_package_activations')->where('id', $item->package_tracking_activation_id)->first(['activated_at', 'reason', 'actor_id']);

        return response()->json(['data' => [
            'item_id' => $item->id, 'mode' => $item->package_tracking_mode->value, 'stock_uom_id' => $item->stock_uom_id,
            'activated_at' => $activation?->activated_at, 'activation_reason' => $activation?->reason,
            'tracked_levels' => $packagings->filter(fn (ItemPackaging $p) => $tracked !== null && in_array($p->id, $tracked, true))->values()->map(fn (ItemPackaging $p) => [
                'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'stock_quantity' => $p->stock_quantity])->all(),
            'packagings' => $packagings->where('active', true)->values()->map(fn (ItemPackaging $p) => [
                'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'stock_quantity' => $p->stock_quantity, 'inner_packaging_id' => $p->inner_packaging_id])->all(),
            'can_activate' => $request->user()?->can('inventory.package-state.activate') === true && $item->package_tracking_mode->value === 'QUANTITY_ONLY',
            'can_view_balances' => $request->user()?->can('inventory.package-state.view') === true,
        ]]);
    }

    /** What stands between this item and activation, per stocked scope. Writes nothing. */
    public function preview(PreviewItemPackageActivationRequest $request, Item $item, PreviewGroupedActivation $preview): JsonResponse
    {
        return response()->json(['data' => $preview($item->id, array_map('intval', $request->validated('checkpoints') ?? []), $request->user())]);
    }

    public function activate(ActivateItemPackageTrackingRequest $request, Item $item, ActivateGroupedPackageTracking $activate): JsonResponse
    {
        $activation = $activate([
            'item_id' => $item->id, 'request_key' => $request->validated('request_key'), 'reason' => $request->validated('reason'),
            'checkpoints' => array_map('intval', $request->validated('checkpoints')), 'tracked_levels' => array_map('intval', $request->validated('tracked_levels') ?? []),
        ], $request->user());

        return response()->json(['data' => ['activation_id' => $activation->id, 'mode' => 'PACKAGE_BALANCE']], 201);
    }
}
