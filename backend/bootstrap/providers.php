<?php

use App\Modules\Accounting\AccountingServiceProvider;
use App\Modules\Administration\AdministrationServiceProvider;
use App\Modules\Approvals\ApprovalsServiceProvider;
use App\Modules\Company\CompanyServiceProvider;
use App\Modules\Documents\DocumentsServiceProvider;
use App\Modules\Estimating\EstimatingServiceProvider;
use App\Modules\Files\FilesServiceProvider;
use App\Modules\Imports\ImportsServiceProvider;
use App\Modules\Inventory\InventoryServiceProvider;
use App\Modules\Jobs\JobsServiceProvider;
use App\Modules\Numbering\NumberingServiceProvider;
use App\Modules\Parties\PartiesServiceProvider;
use App\Modules\Purchasing\PurchasingServiceProvider;
use App\Modules\Sales\SalesServiceProvider;
use App\Modules\Tax\TaxServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\CurrencyServiceProvider;
use App\Providers\UomServiceProvider;

return [
    AppServiceProvider::class,
    AuthServiceProvider::class,
    CurrencyServiceProvider::class,
    UomServiceProvider::class,
    ImportsServiceProvider::class,
    CompanyServiceProvider::class,
    NumberingServiceProvider::class,
    InventoryServiceProvider::class,
    PartiesServiceProvider::class,
    SalesServiceProvider::class,
    AccountingServiceProvider::class,
    AdministrationServiceProvider::class,
    PurchasingServiceProvider::class,
    ApprovalsServiceProvider::class,
    EstimatingServiceProvider::class,
    DocumentsServiceProvider::class,
    FilesServiceProvider::class,
    JobsServiceProvider::class,
    TaxServiceProvider::class,
];
