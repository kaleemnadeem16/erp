<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Modules\Estimating\Models\ResourceRate;
use Illuminate\Support\Facades\DB;

final class DeleteResourceRate
{
    public function __invoke(ResourceRate $resourceRate): void
    {
        DB::transaction(fn () => $resourceRate->delete());
    }
}
