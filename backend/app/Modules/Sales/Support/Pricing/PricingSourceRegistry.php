<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Pricing;

use App\Modules\Sales\Support\Pricing\Sources\AssemblyWorkSourceAdapter;
use App\Modules\Sales\Support\Pricing\Sources\DirectItemSourceAdapter;
use App\Modules\Sales\Support\Pricing\Sources\ManualSourceAdapter;
use App\Modules\Sales\Support\Pricing\Sources\ServiceItemSourceAdapter;

/** The supported source adapters, by kind. A kind with no adapter refuses; nothing falls back to a guess. */
final class PricingSourceRegistry
{
    /** @var array<string, PricingSourceAdapter> */
    private array $adapters = [];

    public function __construct(DirectItemSourceAdapter $direct, ServiceItemSourceAdapter $service, AssemblyWorkSourceAdapter $assembly, ManualSourceAdapter $manual)
    {
        foreach ([$direct, $service, $assembly, $manual] as $adapter) {
            $this->adapters[$adapter->kind()->value] = $adapter;
        }
    }

    public function adapterFor(PricingSourceKind $kind): PricingSourceAdapter
    {
        return $this->adapters[$kind->value]
            ?? throw PricingRefusal::make(PricingRefusal::SOURCE_KIND_UNSUPPORTED, "Pricing a {$kind->value} source is not supported.", 'source');
    }
}
