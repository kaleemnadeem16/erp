<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Requests\PurchaseOrder;

use Illuminate\Foundation\Http\FormRequest;

class CommitInventoryCutoverOpenPurchaseOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('purchasing.cutover.commit') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
