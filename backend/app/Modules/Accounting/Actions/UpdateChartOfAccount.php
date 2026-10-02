<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Models\User;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Support\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class UpdateChartOfAccount
{
    public function __construct(private readonly CompanyContext $companyContext) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(ChartOfAccount $chart_of_account, array $attributes, User $actor): ChartOfAccount
    {
        if (! $actor->can('accounting.chart-of-account.manage')) {
            throw new AuthorizationException;
        }
        if ($chart_of_account->company_id !== $this->companyContext->id()) {
            throw (new ModelNotFoundException)->setModel(ChartOfAccount::class, [$chart_of_account->id]);
        }

        return DB::transaction(function () use ($chart_of_account, $attributes): ChartOfAccount {
            $chart_of_account->update($attributes);

            return $chart_of_account->refresh();
        });
    }
}
