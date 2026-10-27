<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Quotation;

use App\Modules\Sales\Http\Requests\SalesRequest;
use App\Modules\Sales\Support\PermissionVocabulary;
use Illuminate\Validation\Validator;

/**
 * Starting a draft needs `sales.quotation.prepare` (STAGED: refused for everyone until a reviewed holder recipe activates it). The body is handed to the Action
 * untouched and only as JSON: `CreateQuotationDraft` is the one validator of its values and refuses an unknown key rather than ignoring it, which `validated()` would.
 */
class StoreQuotationDraftRequest extends SalesRequest
{
    use DescribesQuotationDraftBody;

    protected function capability(): ?string
    {
        return PermissionVocabulary::QUOTATION_PREPARE;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->headerRules() + ['opportunity_id' => ['sometimes', 'nullable', 'integer', 'min:1']];
    }

    public function withValidator(Validator $validator): void
    {
        $this->requireJsonObject($validator);
    }
}
