<?php

declare(strict_types=1);

namespace App\Modules\Parties\Http\Requests\SupplierSite;

use App\Modules\Parties\Support\SupplierSiteRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The supplier account is the route-bound `{supplier_account}` parameter, not
 * a body field — its company scoping and existence are already enforced by
 * route-model binding (SupplierAccount's own BelongsToCompany global scope).
 * `code` isn't accepted here: CreateSupplierSite derives one from `name`, so
 * the form asks for an address, not an internal identifier.
 */
class StoreSupplierSiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('parties.supplier-site.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            // ISO 3166-1 alpha-2 — no countries table yet, see PartySite::countryCode().
            'country_code' => ['nullable', 'string', 'size:2', 'alpha'],
            'role' => ['required', Rule::enum(SupplierSiteRole::class)],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
