<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Models\User;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Support\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class ArchiveChartOfAccount
{
    public function __construct(private readonly CompanyContext $companyContext) {}

    public function __invoke(ChartOfAccount $chart_of_account, User $actor): void
    {
        if (! $actor->can('accounting.chart-of-account.manage')) {
            throw new AuthorizationException;
        }
        if ($chart_of_account->company_id !== $this->companyContext->id()) {
            throw (new ModelNotFoundException)->setModel(ChartOfAccount::class, [$chart_of_account->id]);
        }

        DB::transaction(fn () => $chart_of_account->delete());
    }
}
