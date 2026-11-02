<?php

declare(strict_types=1);

namespace App\Support\Scope;

use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Company-wide security barrier. Hold the enclosing transaction through the authorised read or
 * write. No authority follows from this lock itself: callers must re-read and prove each duty.
 * This primitive is not yet wired to quotation access or security writers.
 */
final class CompanyAccessFence
{
    /** @return object{generation: int, organisation_generation: int, capability_generation: int} */
    public function lock(bool $exclusive = false): object
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('The company access fence requires an enclosing transaction.');
        }

        $company = app(CompanyContext::class)->id();
        DB::table('company_access_generations')->insertOrIgnore(['company_id' => $company]);
        $query = DB::table('company_access_generations')->where('company_id', $company);
        $row = ($exclusive ? $query->lockForUpdate() : $query->sharedLock())->firstOrFail();

        return (object) ['generation' => (int) $row->generation, 'organisation_generation' => (int) $row->organisation_generation,
            'capability_generation' => (int) $row->capability_generation];
    }
}
