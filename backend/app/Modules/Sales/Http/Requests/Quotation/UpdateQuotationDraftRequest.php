<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\Quotation;

use App\Modules\Sales\Support\PermissionVocabulary;
use Illuminate\Validation\Validator;

/**
 * Changing a draft needs `sales.quotation.prepare` (STAGED) and the input generation the caller last saw. Everything else in the JSON body goes to
 * `UpdateQuotationDraft` untouched, which refuses an unknown header or line key instead of ignoring it.
 */
class UpdateQuotationDraftRequest extends QuotationRequest
{
    use DescribesQuotationDraftBody;

    protected function capability(): ?string
    {
        return PermissionVocabulary::QUOTATION_PREPARE;
    }

    /** @return list<string> */
    protected function extraDuties(): array
    {
        $body = json_decode((string) $this->getContent(), true);
        $body = is_array($body) ? $body : [];
        $lines = [...(is_array($body['add_lines'] ?? null) ? $body['add_lines'] : []), ...(is_array($body['update_lines'] ?? null) ? $body['update_lines'] : [])];
        $has = static fn (string $field): bool => collect($lines)->contains(static fn (mixed $line): bool => is_array($line) && array_key_exists($field, $line));
        $duties = [];
        if (array_key_exists('document_discount', $body) || $has('discount')) {
            $duties[] = PermissionVocabulary::QUOTATION_DISCOUNT;
        }
        if ($has('net_override')) {
            $duties[] = PermissionVocabulary::QUOTATION_PRICE_OVERRIDE;
        }

        return $duties;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['expected_generation' => ['required', 'integer', 'min:1']] + $this->headerRules() + $this->lineRules();
    }

    public function withValidator(Validator $validator): void
    {
        $this->requireJsonObject($validator);
    }
}
