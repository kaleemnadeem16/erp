<?php

declare(strict_types=1);

namespace App\Modules\Company\Http\Resources;

use App\Models\Company;
use App\Modules\Company\Support\CompanyFields;
use App\Support\CompanyLocks\CompanyLocks;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The company as the Company screen needs it: the values, the form description from the field registry (section, label, type,
 * whether the caller may edit it) and, for the lifecycle-guarded fields, why they are locked right now. The locks come from the
 * same CompanyLocks the model guard uses.
 *
 * @mixin Company
 */
class CompanyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Company $company */
        $company = $this->resource;
        $locks = app(CompanyLocks::class);
        $lockReasons = [
            'base_currency_id' => $locks->baseCurrency($company),
            'fiscal_year_start_month' => $locks->fiscalYearStartMonth($company),
        ];

        $values = [];
        $fields = [];
        foreach (CompanyFields::all() as $field) {
            $values[$field->key] = $company->getAttribute($field->key);
            $lock = $lockReasons[$field->key] ?? null;
            $fields[] = [
                'key' => $field->key,
                'section' => $field->section,
                'label' => $field->label,
                'description' => $field->description,
                'type' => $field->type->value,
                'governed' => $field->governed,
                'nullable' => $field->nullable,
                'max_length' => $field->maxLength,
                'can_edit' => $request->user()?->can($field->permission) === true,
                'locked' => $lock !== null,
                'locked_reason' => $lock,
            ];
        }

        $sections = [];
        foreach (CompanyFields::SECTIONS as $key => $section) {
            $sections[] = ['key' => $key, 'label' => $section['label'], 'description' => $section['description']];
        }

        return [
            'id' => $company->id,
            'values' => $values,
            'base_currency' => $company->baseCurrency === null ? null : ['id' => $company->baseCurrency->id, 'code' => $company->baseCurrency->code, 'name' => $company->baseCurrency->name],
            'sections' => $sections,
            'fields' => $fields,
            'timezone_is_default' => $company->timezone === 'UTC',
        ];
    }
}
