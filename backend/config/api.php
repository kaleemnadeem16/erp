<?php

return [
    /*
     * Authenticated requests allowed per user per minute (docs/architecture/api-conventions.md: 120). Production keeps the
     * default. A scripted end-to-end run drives one user through dozens of screens a minute — far faster than any person,
     * whose screens make only a handful of requests each — so the development stack raises it via the environment rather than
     * loosening the rule everywhere or letting the suite fail intermittently with "Too Many Attempts".
     */
    'rate_limit_per_minute' => (int) env('API_RATE_LIMIT_PER_MINUTE', 120),
];
