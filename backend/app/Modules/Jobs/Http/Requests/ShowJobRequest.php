<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Http\Requests;

use App\Modules\Jobs\Support\JobAccess;
use Dedoc\Scramble\Attributes\IgnoreParam;
use Illuminate\Foundation\Http\FormRequest;

#[IgnoreParam('include', in: 'query')]
class ShowJobRequest extends FormRequest
{
    protected function passedValidation(): void
    {
        // Route constraints reject non-numeric ids; reject overflow before PHP coerces the controller's int argument.
        if ($this->route('job') !== null && filter_var($this->route('job'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            abort(404, 'The Job was not found.');
        }
    }

    public function authorize(): bool
    {
        return JobAccess::may($this->user(), 'job.view');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['include' => ['prohibited']];
    }
}
