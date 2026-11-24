<?php

declare(strict_types=1);

namespace Tests\E2E\RealBackend;

use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * TEST ONLY. Production resolves one company (CompanyContext::id() is 1 until the multi-company gate, #119). To prove company isolation through real HTTP the harness gives each
 * request the company of the person holding the bearer token. It reads the token with raw queries (no model, no company scope) and falls back to company 1 for anything it cannot
 * prove, so an unknown or forged token can never select another company's data.
 */
final class TokenCompanyContext extends CompanyContext
{
    private ?int $resolved = null;

    public function id(): int
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }
        $company = 1;
        $bearer = request()->bearerToken();
        if (is_string($bearer) && str_contains($bearer, '|')) {
            [$tokenId, $plain] = explode('|', $bearer, 2);
            $row = ctype_digit($tokenId) ? DB::table('personal_access_tokens')->where('id', (int) $tokenId)->first() : null;
            if ($row !== null && hash_equals((string) $row->token, hash('sha256', $plain))) {
                $company = (int) (DB::table('users')->where('id', $row->tokenable_id)->value('company_id') ?: 1);
            }
        }

        return $this->resolved = $company;
    }
}
