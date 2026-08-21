<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Models\ChartOfAccount;
use Illuminate\Support\Facades\DB;

final class CreateChartOfAccount
{
    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes): ChartOfAccount
    {
        return DB::transaction(function () use ($attributes): ChartOfAccount {
            $chart_of_account = ChartOfAccount::create($attributes);

            return $chart_of_account->refresh();
        });
    }
}
