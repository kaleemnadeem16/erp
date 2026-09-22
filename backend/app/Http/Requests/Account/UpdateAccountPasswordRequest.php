<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class UpdateAccountPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Checked against $this->user()->password directly rather than via Laravel's
            // `current_password` rule, which validates against a named guard — this app's
            // sanctum-authenticated requests (both cookie and bearer-token) don't reliably
            // resolve through the single 'web' guard config/auth.php declares.
            'current_password' => [
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! Hash::check($value, $this->user()->password)) {
                        $fail('The provided password does not match your current password.');
                    }
                },
            ],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
