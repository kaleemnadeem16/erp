<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Requests\InventoryCutover;

use App\Rules\Support\CompanyScoped;
use Illuminate\Foundation\Http\FormRequest;

class AttachInventoryCutoverBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.cutover.stage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'import_batch_id' => ['required', 'integer', CompanyScoped::exists('import_batches')],
        ];
    }
}
