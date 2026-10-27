<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Quotation;

use App\Modules\Sales\Http\Requests\SalesRequest;
use App\Modules\Sales\Support\PermissionVocabulary;
use Illuminate\Validation\Validator;

/**
 * Changing a draft needs `sales.quotation.prepare` (STAGED) and the input generation the caller last saw. Everything else in the JSON body goes to
 * `UpdateQuotationDraft` untouched, which refuses an unknown header or line key instead of ignoring it.
 */
class UpdateQuotationDraftRequest extends SalesRequest
{
    protected function capability(): ?string
    {
        return PermissionVocabulary::QUOTATION_PREPARE;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['expected_generation' => ['required', 'integer', 'min:1']];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->isJson()) {
                $validator->errors()->add('body', 'The request body must be JSON.');
            }
        });
    }
}
