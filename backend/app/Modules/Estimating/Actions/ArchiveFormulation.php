<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Modules\Estimating\Models\Formulation;
use App\Modules\Estimating\Support\FormulationStatus;
use Illuminate\Support\Facades\DB;

final class ArchiveFormulation
{
    public function __invoke(Formulation $formulation): void
    {
        DB::transaction(fn () => $formulation->update(['status' => FormulationStatus::Archived]));
    }
}
