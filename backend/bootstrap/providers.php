<?php

use App\Modules\Accounting\AccountingServiceProvider;
use App\Modules\Imports\ImportsServiceProvider;
use App\Modules\Inventory\InventoryServiceProvider;
use App\Modules\Parties\PartiesServiceProvider;
use App\Modules\Purchasing\PurchasingServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\CurrencyServiceProvider;

return [
    AppServiceProvider::class,
    AuthServiceProvider::class,
    CurrencyServiceProvider::class,
    ImportsServiceProvider::class,
    InventoryServiceProvider::class,
    PartiesServiceProvider::class,
    AccountingServiceProvider::class,
    PurchasingServiceProvider::class,
];
