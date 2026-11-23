<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Http\Requests;

use App\Modules\Jobs\Support\JobAccess;
use App\Modules\Jobs\Support\Lifecycle\JobLifecycleOperation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class JobOperationRequest extends FormRequest
{
    abstract public function operation(): string;

    /** @return array<string, mixed> */
    abstract public function rules(): array;

    public function authorize(): bool
    {
        return JobAccess::mayAll($this->user(), ...(JobLifecycleOperation::DUTIES[$this->operation()] ?? []));
    }

    protected function passedValidation(): void
    {
        if (filter_var($this->route('job'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            abort(404, 'The Job was not found.');
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $key) {
                $validator->errors()->add($key, 'This field is not accepted.');
            }
        });
    }
}
