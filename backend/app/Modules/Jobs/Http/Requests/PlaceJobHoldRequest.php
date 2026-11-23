<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Http\Requests;

final class PlaceJobHoldRequest extends JobCommandRequest
{
    public function operation(): string
    {
        return 'HOLD';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + [
            'reason' => $this->textRules(required: true),
        ];
    }
}
