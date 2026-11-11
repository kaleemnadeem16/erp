<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\CustomerDecision;

use App\Modules\Sales\Http\Requests\SalesRequest;
use App\Modules\Sales\Support\PermissionVocabulary;
use Illuminate\Contracts\Validation\Validator;

/**
 * Base of the customer decision requests: the preliminary duty check (the Action repeats it inside its transaction, under the company access fence). Both duties are STAGED, so
 * every caller is refused until the manifest activates them.
 */
abstract class CustomerDecisionRequest extends SalesRequest
{
    /** @return array<string, mixed> */
    abstract public function rules(): array;

    protected function viewing(): bool
    {
        return false;
    }

    /** A body carries only the fields the contract names: there is no "accepted", "consent" or amount field to send, and sending one is refused rather than ignored. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $allowed = array_unique(array_map(fn (string $key): string => explode('.', $key)[0], array_keys($this->rules())));
            foreach (array_diff(array_keys($this->isJson() ? $this->json()->all() : $this->post()), $allowed) as $unknown) {
                $v->errors()->add((string) $unknown, 'This field is not part of the request.');
            }
        });
    }

    protected function capability(): string
    {
        return $this->viewing() ? PermissionVocabulary::CUSTOMER_DECISION_VIEW : PermissionVocabulary::CUSTOMER_DECISION_EVIDENCE_RECORD;
    }
}
