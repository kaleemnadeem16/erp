<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\MaterialPlan;

use App\Modules\Parties\Models\CustomerAccount;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Quotation\QuotationRefusal;

/**
 * Binds customer-specific material resolution (a customer's own formulation) to the quotation's authorised recipient (ADR 0190). The customer is never chosen by the seller: it is the
 * customer account of the revision's recipient Party, or none (a prospect has no account, and resolves the company default). A request that names a different customer is refused,
 * and the database checks the same binding on every adopted plan.
 */
final class MaterialPlanRecipient
{
    /** The recipient's customer account, or null when the recipient has none or the quotation has no recipient yet. */
    public static function customerFor(QuotationRevision $revision): ?int
    {
        if ($revision->recipient_party_id === null) {
            return null;
        }
        $id = CustomerAccount::query()->where('party_id', $revision->recipient_party_id)->value('id');

        return $id === null ? null : (int) $id;
    }

    /** @param  array<string, mixed>  $inputs */
    public static function assertRequestMatches(QuotationRevision $revision, array $inputs): ?int
    {
        $customer = self::customerFor($revision);
        if (array_key_exists('customer_id', $inputs) && $inputs['customer_id'] !== $customer) {
            throw QuotationRefusal::make(QuotationRefusal::MATERIAL_PLAN_CUSTOMER_MISMATCH, 'Material resolution is for the quotation\'s own recipient; a different customer cannot be named.', 'customer_id');
        }

        return $customer;
    }
}
