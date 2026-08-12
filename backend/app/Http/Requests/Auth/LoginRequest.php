<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            // Present only for the mobile flow — its value labels the issued token
            // (e.g. "Kaleem's iPhone") and its presence is what selects token mode
            // over cookie mode. See docs/decisions/0005-sanctum-authentication.md.
            'device_name' => ['sometimes', 'string', 'max:255'],
        ];
    }
}
