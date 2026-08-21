<?php

use App\Modules\Accounting\AccountingServiceProvider;
use App\Modules\Inventory\InventoryServiceProvider;
use App\Modules\Parties\PartiesServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\CurrencyServiceProvider;

return [
    AppServiceProvider::class,
    AuthServiceProvider::class,
    CurrencyServiceProvider::class,
    InventoryServiceProvider::class,
    PartiesServiceProvider::class,
    AccountingServiceProvider::class,
];
