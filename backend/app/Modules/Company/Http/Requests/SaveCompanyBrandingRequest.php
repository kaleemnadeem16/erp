<?php

declare(strict_types=1);

namespace App\Modules\Company\Http\Requests;

use App\Modules\Company\Actions\SaveCompanyBranding;
use App\Modules\Company\Support\CompanyAccess;
use App\Modules\Company\Support\CompanyDutyManifest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Changes the company branding (`company.branding.manage`, STAGED: nobody passes today; the Action asks again). Multipart because a logo may travel with it. Only the parts that are
 * sent change; `remove_logo` clears the logo. A logo is PNG or JPEG, at most 2 MB; the file itself is sniffed and sanitised by the Action, whatever its name or declared type says.
 */
class SaveCompanyBrandingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can(CompanyDutyManifest::BRANDING) && CompanyAccess::mayManageBranding($user);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'display_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'tagline' => ['sometimes', 'nullable', 'string', 'max:160'],
            'remove_logo' => ['sometimes', 'boolean'],
            'logo' => ['sometimes', 'file', 'max:'.intdiv(SaveCompanyBranding::MAX_LOGO_BYTES, 1024)],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
