<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\DiscountAuthority;

use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/** @internal Acquire only after CompanyAccessFence, before any quotation or approval lock (ADR 0164). */
final class AuthorityFence
{
    public function lock(): int
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Authority evaluation requires an enclosing transaction and the company access fence.');
        }
        $company = app(CompanyContext::class)->id();
        DB::table('sales_authority_heads')->insertOrIgnore(['company_id' => $company]);

        return (int) DB::table('sales_authority_heads')->where('company_id', $company)->lockForUpdate()->value('generation');
    }
}
