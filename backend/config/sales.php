<?php

return [
    /*
     * LOCAL DEVELOPMENT ONLY. When true AND the application environment is exactly `local`, the staged quotation duties (`sales.quotation.view`,
     * `sales.quotation.prepare`) are treated as ACTIVE so the quotation screens can be exercised on a developer's machine; in any other environment the flag is
     * ignored (and reported), the shipped STAGED defaults apply, and nothing is registered or granted. It confers nothing by itself: a person still needs an
     * explicit grant (`php artisan sales:dev-grant-quotation`). See Support/Authority/SalesDevPreview.php.
     */
    'dev_preview' => [
        'quotation_duties' => (bool) env('SALES_DEV_QUOTATION_DUTIES', false),
    ],
];
