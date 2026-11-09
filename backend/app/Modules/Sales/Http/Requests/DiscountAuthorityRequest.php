<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests;

use App\Modules\Sales\Support\Kernel\ActiveActor;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\SalesAccess;
use App\Support\Scope\CompanyAccessFence;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

abstract class DiscountAuthorityRequest extends FormRequest
{
    /** @return array<string, mixed> */
    abstract public function rules(): array;

    protected function capability(): string
    {
        return PermissionVocabulary::FINANCIAL_CONFIGURE;
    }

    public function authorize(): bool
    {
        return DB::transaction(function (): bool {
            app(CompanyAccessFence::class)->lock();
            $person = $this->user();

            return $person !== null && SalesAccess::may(ActiveActor::current($person), $this->capability());
        });
    }

    /** @return array<string, mixed> */
    protected function writeRules(): array
    {
        return ['expected_generation' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:1000']];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $key = trim((string) $this->header('Idempotency-Key'));
            if (! $this->isMethod('GET') && ($key === '' || mb_strlen($key) > 85)) {
                $validator->errors()->add('request_key', 'Provide an Idempotency-Key of at most 85 characters.');
            }
            $allowed = array_filter(array_keys($this->rules()), fn (string $key): bool => ! str_contains($key, '.'));
            foreach (array_diff(array_keys($this->all()), $allowed) as $key) {
                $validator->errors()->add($key, 'This field is not supported.');
            }
        });
    }
}
