<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Pricing;

/** The four commercial source shapes of one resolver (R3): the declared nature is checked against the real source, never trusted. */
enum PricingSourceKind: string
{
    case DirectItem = 'DIRECT_ITEM';
    case ServiceItem = 'SERVICE_ITEM';
    case AssemblyWork = 'ASSEMBLY_WORK';
    case Manual = 'MANUAL';
}
