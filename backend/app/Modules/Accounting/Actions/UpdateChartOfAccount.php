<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Models\ChartOfAccount;
use Illuminate\Support\Facades\DB;

final class UpdateChartOfAccount
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(ChartOfAccount $chart_of_account, array $attributes): ChartOfAccount
    {
        return DB::transaction(function () use ($chart_of_account, $attributes): ChartOfAccount {
            $chart_of_account->update($attributes);

            return $chart_of_account->refresh();
        });
    }
}
