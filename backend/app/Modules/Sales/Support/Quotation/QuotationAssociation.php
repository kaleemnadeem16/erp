<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation;

/** The consistency rule of a CRM association (ADR 0099): when both the Opportunity and the draft know their Party, it is the same Party. An Opportunity with no Party, or a draft with no recipient yet, is allowed. */
final class QuotationAssociation
{
    public static function assertRecipientMatches(?int $opportunityPartyId, ?int $recipientPartyId): void
    {
        if ($opportunityPartyId !== null && $recipientPartyId !== null && $opportunityPartyId !== $recipientPartyId) {
            throw QuotationRefusal::make(QuotationRefusal::OPPORTUNITY_PARTY_MISMATCH, 'The recipient Party must be the Party of the linked Opportunity.', 'recipient_party_id');
        }
    }
}
