<?php

return [
    /*
     * Purchase-order FX governance (issue #90, ADR 0165). A rate typed on a purchase order is reconciled with the company's governed exchange rate
     * (the manual master, ExchangeRate) for the order currency to the company base currency, of the same rate type, dated on or before the order's
     * rate date and no older than max_rate_age_days. A rate within tolerance_percent of it is accepted; a different one is accepted only as an
     * evidenced FIXED rate by a person holding purchasing.purchase-order.fx-override; anything else is refused.
     */
    'fx' => [
        'tolerance_percent' => env('PURCHASING_FX_TOLERANCE_PERCENT', '0.5'),
        'max_rate_age_days' => (int) env('PURCHASING_FX_MAX_RATE_AGE_DAYS', 7),
        'min_override_reason_length' => 10,
    ],
];
