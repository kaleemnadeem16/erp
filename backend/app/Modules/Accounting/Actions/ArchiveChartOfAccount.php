<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Models\ChartOfAccount;
use Illuminate\Support\Facades\DB;

final class ArchiveChartOfAccount
{
    public function __invoke(ChartOfAccount $chart_of_account): void
    {
        DB::transaction(fn () => $chart_of_account->delete());
    }
}
