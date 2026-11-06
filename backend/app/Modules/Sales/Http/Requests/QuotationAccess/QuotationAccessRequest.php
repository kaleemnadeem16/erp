<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests\QuotationAccess;

use App\Modules\Sales\Support\Quotation\QuotationAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

/** Preliminary current capability/scope guard; each Action repeats it under its fence. */
abstract class QuotationAccessRequest extends FormRequest
{
    /** @return list<string> */
    abstract protected function duties(): array;

    /** @return array<string,mixed> */
    abstract public function rules(): array;

    public function authorize(): bool
    {
        if ($this->user() === null) {
            return false;
        }
        try {
            DB::transaction(fn () => app(QuotationAccess::class)->queryFor($this->user(), $this->duties(), $this instanceof ShowQuotationAccessRequest));

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    /** @return array<string,mixed> */
    protected function writeRules(): array
    {
        return ['expected_ownership_generation' => ['required', 'integer', 'min:1'], 'expected_access_generation' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:1000']];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, 'This field is not accepted.');
            }
        });
    }
}
