<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Http\Requests;

final class CompleteJobRequest extends JobCommandRequest
{
    public function operation(): string
    {
        return 'COMPLETE';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + [
            'statement' => $this->textRules(required: true),
        ];
    }
}
