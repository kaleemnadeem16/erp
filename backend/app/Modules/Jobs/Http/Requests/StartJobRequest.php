<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Http\Requests;

final class StartJobRequest extends JobCommandRequest
{
    public function operation(): string
    {
        return 'START';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + [
            'reason' => $this->textRules(),
        ];
    }
}
