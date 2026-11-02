<?php

declare(strict_types=1);

namespace App\Modules\Tax\Support\Quotation;

use App\Modules\Tax\Support\TaxRefusal;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * Tax-owned issue-time barrier. The caller must hold an outer transaction until issue commits;
 * this lock serialises with Tax's generation-triggered writers and is not an authority check.
 * Acquire it after the caller's other ordered source locks, before determining/rechecking Tax.
 */
final class QuotationTaxGenerationFence
{
    public function lock(?int $expectedGeneration = null): int
    {
        if (DB::transactionLevel() < 1) {
            throw TaxRefusal::because('TAX_FENCE_TRANSACTION_REQUIRED', 'Tax evidence must be fenced inside the issue transaction.');
        }

        $companyId = app(CompanyContext::class)->id();
        DB::table('tax_generations')->insertOrIgnore(['company_id' => $companyId, 'generation' => 0]);
        $row = DB::table('tax_generations')->where('company_id', $companyId)->lockForUpdate()->first();
        if ($row === null) {
            throw TaxRefusal::because('TAX_FENCE_UNAVAILABLE', 'Tax evidence cannot be fenced.');
        }

        $generation = (int) $row->generation;
        if ($expectedGeneration !== null && $expectedGeneration !== $generation) {
            throw TaxRefusal::because('TAX_GENERATION_STALE', 'Tax evidence changed; review the quotation again.');
        }

        return $generation;
    }
}
