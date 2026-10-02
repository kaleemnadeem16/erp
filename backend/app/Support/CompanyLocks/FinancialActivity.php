<?php

declare(strict_types=1);

namespace App\Support\CompanyLocks;

/**
 * The registry of financial-activity probes (ADR 0024, ADR 0081). A company's base currency can be changed only while none of them
 * finds anything: historical base amounts are pinned at the rate in force when they were recorded and never recomputed (ADR 0012).
 */
final class FinancialActivity
{
    /** @var list<FinancialActivityProbe> */
    private array $probes = [];

    public function register(FinancialActivityProbe $probe): void
    {
        $this->probes[] = $probe;
    }

    /** @return list<string> the labels of the kinds of financial record that already exist */
    public function existing(): array
    {
        $labels = [];
        foreach ($this->probes as $probe) {
            if ($probe->exists()) {
                $labels[] = $probe->label();
            }
        }

        return $labels;
    }
}
