<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\Sales\Models\QuotationFamily;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Raw storage fixtures only: they never register/activate a duty or substitute for an Action authority proof. */
trait BuildsQuotationShareStorage
{
    /** @param array<string,mixed> $overrides */
    private function rawGrant(QuotationFamily $family, User $issuer, User $recipient, array $overrides = []): int
    {
        $family = $family->fresh();
        $epoch = DB::table('company_access_generations')->where('company_id', $family->company_id)->first();

        return DB::table('quotation_access_grants')->insertGetId(array_replace([
            'company_id' => $family->company_id, 'family_id' => $family->id, 'issuer_id' => $issuer->id, 'recipient_id' => $recipient->id, 'actor_id' => $issuer->id,
            'level' => 'VIEW', 'starts_at' => DB::raw('clock_timestamp()'), 'ends_at' => null,
            'issuer_authorization_generation' => DB::table('users')->where('id', $issuer->id)->value('authorization_generation'),
            'recipient_authorization_generation' => DB::table('users')->where('id', $recipient->id)->value('authorization_generation'),
            'organisation_generation' => $epoch->organisation_generation, 'capability_generation' => $epoch->capability_generation,
            'ownership_generation' => $family->ownership_generation, 'reason' => 'Private synthetic share storage fixture',
        ], $overrides));
    }

    /** @param array<string,mixed> $overrides */
    private function rawRevoke(QuotationFamily $family, int $grantId, User $actor, array $overrides = []): int
    {
        return DB::table('quotation_access_revocations')->insertGetId(array_replace([
            'company_id' => $family->company_id, 'family_id' => $family->id, 'grant_id' => $grantId,
            'revoked_by' => $actor->id, 'reason' => 'Private synthetic revocation storage fixture',
        ], $overrides));
    }

    /** @param list<string> $states */
    private function assertStorageRefuses(Closure $attempt, array $states = ['23514']): void
    {
        try {
            DB::transaction(function () use ($attempt): void {
                $attempt();
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
            $this->fail('Invalid access evidence must not commit.');
        } catch (QueryException $failure) {
            $this->assertContains($failure->errorInfo[0], $states);
        }
    }
}
