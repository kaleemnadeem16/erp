<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Requests\PurchaseOrder;

use App\Modules\Inventory\Support\WarehouseScope;
use Illuminate\Foundation\Http\FormRequest;

class CommitInventoryCutoverOpenPurchaseOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('purchasing.cutover.commit') === true
            && WarehouseScope::unrestricted($this->user(), 'purchasing.cutover.commit');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
