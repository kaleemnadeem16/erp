<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

/** What the append-only history records. */
final class SalesEvent
{
    public const LEAD_RECEIVED = 'LEAD_RECEIVED';

    public const LEAD_ASSIGNED = 'LEAD_ASSIGNED';

    public const LEAD_PARTY_LINKED = 'LEAD_PARTY_LINKED';

    public const LEAD_QUALIFIED = 'LEAD_QUALIFIED';

    public const LEAD_DISQUALIFIED = 'LEAD_DISQUALIFIED';

    public const LEAD_REVIVED = 'LEAD_REVIVED';

    public const LEAD_ENQUIRY_CORRECTED = 'LEAD_ENQUIRY_CORRECTED';

    public const LEAD_CONVERTED = 'LEAD_CONVERTED';

    public const OPPORTUNITY_CREATED = 'OPPORTUNITY_CREATED';

    public const OWNER_CHANGED = 'OWNER_CHANGED';

    public const STAGE_CHANGED = 'STAGE_CHANGED';

    public const CLOSED = 'CLOSED';

    public const REOPENED = 'REOPENED';

    public const OPPORTUNITY_ATTRIBUTION_CORRECTED = 'OPPORTUNITY_ATTRIBUTION_CORRECTED';
}
