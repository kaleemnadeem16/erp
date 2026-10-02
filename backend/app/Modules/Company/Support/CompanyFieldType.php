<?php

declare(strict_types=1);

namespace App\Modules\Company\Support;

/** How the Company screen renders and validates a field. Each type has a real widget; there is no free-form type. */
enum CompanyFieldType: string
{
    case Text = 'TEXT';
    case Code = 'CODE';
    case Email = 'EMAIL';
    case Url = 'URL';
    case Phone = 'PHONE';
    case Country = 'COUNTRY';
    case Timezone = 'TIMEZONE';
    case Currency = 'CURRENCY';
    case Month = 'MONTH';
}
