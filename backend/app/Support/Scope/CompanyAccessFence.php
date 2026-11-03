<?php

declare(strict_types=1);

namespace App\Support\Scope;

use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Company-wide security barrier. Hold the enclosing transaction through the authorised read or
 * write. No authority follows from this lock itself: callers must re-read and prove each duty.
 * Protected quotation operations and raw security writers share this barrier.
 */
final class CompanyAccessFence
{
    public const LOCK_TIMEOUT_MS = 1000;

    public const STATEMENT_TIMEOUT_MS = 30000;

    private function boundWaits(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('The company access fence requires an enclosing transaction.');
        }
        // Transaction-local: keep the bounds through materialisation/work, never on the next
        // pooled request. Honour a caller's stricter bound rather than silently extending it.
        DB::select("SELECT set_config(name, LEAST(COALESCE(NULLIF(setting::bigint, 0), bounds.maximum), bounds.maximum)::text, true)
            FROM pg_settings JOIN (VALUES ('lock_timeout', ?::bigint), ('statement_timeout', ?::bigint)) AS bounds(parameter, maximum)
            ON name = bounds.parameter", [self::LOCK_TIMEOUT_MS, self::STATEMENT_TIMEOUT_MS]);
    }

    /** Match the conservative writer trigger: acquire all company barriers in ascending order. */
    public function lockAll(): void
    {
        $this->boundWaits();
        DB::statement('INSERT INTO company_access_generations(company_id) SELECT id FROM companies ORDER BY id ON CONFLICT DO NOTHING');
        DB::table('company_access_generations')->orderBy('company_id')->lockForUpdate()->get();
    }

    /** @return object{generation: int, organisation_generation: int, capability_generation: int} */
    public function lock(bool $exclusive = false): object
    {
        $this->boundWaits();

        $company = app(CompanyContext::class)->id();
        $query = fn () => DB::table('company_access_generations')->where('company_id', $company);
        $row = ($exclusive ? $query()->lockForUpdate() : $query()->sharedLock())->first();
        if ($row === null) {
            // Only the first protected read of a company creates its barrier row; every later read is a pure locking SELECT, never a write statement.
            DB::table('company_access_generations')->insertOrIgnore(['company_id' => $company]);
            $row = ($exclusive ? $query()->lockForUpdate() : $query()->sharedLock())->firstOrFail();
        }

        return (object) ['generation' => (int) $row->generation, 'organisation_generation' => (int) $row->organisation_generation,
            'capability_generation' => (int) $row->capability_generation];
    }
}
