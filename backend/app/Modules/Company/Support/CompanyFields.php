<?php

declare(strict_types=1);

namespace App\Modules\Company\Support;

use Illuminate\Validation\Rule;

/**
 * The registry of company-level fields (ADR 0081), grouped into the sections the Company screen shows. The request validates from
 * it, the resource describes the form from it, the action audits from it, and the screen renders from that description — so a new
 * company-wide setting is one definition here plus its column, not a new page.
 *
 * Ownership rule: a field belongs here only if more than one module reads it and it is not another module's domain policy.
 * Inventory, Purchasing, Approvals and Numbering policies stay with their modules.
 */
final class CompanyFields
{
    public const PROFILE = 'company.profile.manage';

    public const SETTINGS = 'company.settings.manage';

    /** Letters, digits, space and . / - only, starting with a letter or digit (matches the database CHECK). */
    private const IDENTIFIER = ['string', 'regex:/^[A-Za-z0-9][A-Za-z0-9 .\/-]{0,49}$/'];

    /** @var array<string, array{label: string, description: string}> */
    public const SECTIONS = [
        'GENERAL' => ['label' => 'General', 'description' => 'Who the company is.'],
        'LOCALIZATION' => ['label' => 'Localization and finance', 'description' => 'Company-wide settings that every part of the system relies on.'],
        'REGISTRATION' => ['label' => 'Registration and tax', 'description' => 'The identifiers that appear on invoices and statutory documents.'],
        'CONTACT' => ['label' => 'Contact and address', 'description' => 'How people reach the company.'],
    ];

    /** @return list<CompanyField> */
    public static function all(): array
    {
        return [
            new CompanyField('name', 'GENERAL', 'Company name', 'The name shown on screens and printed documents.', CompanyFieldType::Text, ['string', 'min:1', 'max:255'], self::PROFILE, nullable: false, maxLength: 255),
            new CompanyField('legal_name', 'GENERAL', 'Legal name', 'The registered name, when it differs from the name above.', CompanyFieldType::Text, ['string', 'max:255'], self::PROFILE, maxLength: 255),
            new CompanyField(
                'code', 'GENERAL', 'Short code',
                'A short, stable code (letters and digits, 2–10 characters) used in document numbers such as {COMPANY}. Unlike the name it should rarely change; numbers already issued keep the code they were issued with.',
                CompanyFieldType::Code, ['string', 'regex:/^[A-Z0-9]{2,10}$/'], self::PROFILE, governed: true, maxLength: 10,
            ),
            new CompanyField(
                'timezone', 'LOCALIZATION', 'Time zone',
                'The time zone of the company\'s books and business day. Dates in document numbers, the "current day" for backdating limits and accounting-day boundaries all follow it. A document issued at 00:30 local time on the 1st belongs to the new month only if this is right.',
                CompanyFieldType::Timezone, ['string', 'timezone'], self::SETTINGS, governed: true, nullable: false,
            ),
            new CompanyField(
                'base_currency_id', 'LOCALIZATION', 'Base currency',
                'The currency every amount is reported and pinned in. It can be changed only until the first financial record exists.',
                CompanyFieldType::Currency, ['integer', Rule::exists('currencies', 'id')], self::SETTINGS, governed: true,
            ),
            new CompanyField(
                'fiscal_year_start_month', 'LOCALIZATION', 'Fiscal year starts in',
                'The month a fiscal year begins. It can be changed until the first accounting period is closed.',
                CompanyFieldType::Month, ['integer', 'between:1,12'], self::SETTINGS, governed: true, nullable: false,
            ),
            new CompanyField('registration_number', 'REGISTRATION', 'Company registration number', 'The number the company is registered under with the corporate registry (for example SECP or Companies House).', CompanyFieldType::Text, [...self::IDENTIFIER], self::PROFILE, maxLength: 50),
            new CompanyField('tax_number', 'REGISTRATION', 'Tax number', 'The national tax identifier (for example NTN, TIN or EIN).', CompanyFieldType::Text, [...self::IDENTIFIER], self::PROFILE, maxLength: 50),
            new CompanyField('sales_tax_number', 'REGISTRATION', 'Sales tax / VAT / GST number', 'The indirect-tax registration number (for example STRN, VAT or GSTIN), where the company is registered for it.', CompanyFieldType::Text, [...self::IDENTIFIER], self::PROFILE, maxLength: 50),
            new CompanyField('country_code', 'CONTACT', 'Country', 'The country the company is based in.', CompanyFieldType::Country, ['string', 'regex:/^[A-Z]{2}$/'], self::PROFILE),
            new CompanyField('address_line1', 'CONTACT', 'Address line 1', '', CompanyFieldType::Text, ['string', 'max:255'], self::PROFILE, maxLength: 255),
            new CompanyField('address_line2', 'CONTACT', 'Address line 2', '', CompanyFieldType::Text, ['string', 'max:255'], self::PROFILE, maxLength: 255),
            new CompanyField('city', 'CONTACT', 'City', '', CompanyFieldType::Text, ['string', 'max:120'], self::PROFILE, maxLength: 120),
            new CompanyField('state', 'CONTACT', 'State or province', '', CompanyFieldType::Text, ['string', 'max:120'], self::PROFILE, maxLength: 120),
            new CompanyField('postal_code', 'CONTACT', 'Postal code', '', CompanyFieldType::Text, ['string', 'max:30'], self::PROFILE, maxLength: 30),
            new CompanyField('email', 'CONTACT', 'Email', 'A general contact address for the company.', CompanyFieldType::Email, ['email:rfc', 'max:255'], self::PROFILE, maxLength: 255),
            new CompanyField('phone', 'CONTACT', 'Phone', '', CompanyFieldType::Phone, ['string', 'regex:/^[0-9+()\-\s.]{5,40}$/'], self::PROFILE, maxLength: 40),
            new CompanyField('website', 'CONTACT', 'Website', 'Include https://', CompanyFieldType::Url, ['url', 'max:255'], self::PROFILE, maxLength: 255),
        ];
    }

    public static function find(string $key): ?CompanyField
    {
        foreach (self::all() as $field) {
            if ($field->key === $key) {
                return $field;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_map(fn (CompanyField $field): string => $field->key, self::all());
    }
}
