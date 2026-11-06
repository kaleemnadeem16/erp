<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\DiscountAuthority;

use App\Models\User;
use App\Modules\Sales\Support\Kernel\ActiveActor;
use App\Modules\Sales\Support\Kernel\OperationIntent;
use App\Modules\Sales\Support\SalesAccess;
use App\Support\CompanyContext;
use App\Support\Scope\CompanyAccessFence;
use Closure;
use Illuminate\Support\Facades\DB;

/** Configuration receipts and the common fence. Never confers authority on a caller. */
final class AuthorityOperation
{
    /**
     * @param  array<string, mixed>  $intent
     * @param  Closure(): array<string, mixed>  $work
     * @return array<string, mixed>
     */
    public function run(User $actor, string $duty, string $key, int $expectedGeneration, array $intent, Closure $work): array
    {
        if (trim($key) === '' || mb_strlen($key) > 85) {
            throw AuthorityRefusal::make('REQUEST_KEY_REQUIRED', 'Give this operation a request key of at most 85 characters.', 'request_key');
        }

        return DB::transaction(function () use ($actor, $duty, $key, $expectedGeneration, $intent, $work): array {
            app(CompanyAccessFence::class)->lockAll();
            $actor = ActiveActor::require($actor, 'configure discount authority');
            SalesAccess::require($actor, $duty, 'configure discount authority');
            $generation = app(AuthorityFence::class)->lock();
            $company = app(CompanyContext::class)->id();
            $hash = OperationIntent::hash('sales.authority', ['duty' => $duty, 'generation' => $expectedGeneration, 'intent' => $intent]);
            $receipt = DB::table('sales_authority_operation_receipts')->where('company_id', $company)->where('actor_id', $actor->id)->where('request_key', $key)->first();
            if ($receipt !== null) {
                if (! hash_equals($receipt->intent_hash, $hash)) {
                    throw AuthorityRefusal::make('REQUEST_KEY_REUSED', 'That request key was used for a different authority change.', 'request_key');
                }

                return json_decode($receipt->result, true, flags: JSON_THROW_ON_ERROR);
            }
            if ($generation !== $expectedGeneration) {
                throw AuthorityRefusal::make('AUTHORITY_STALE', 'Authority configuration changed. Reload it before continuing.');
            }
            $result = $work();
            DB::table('sales_authority_operation_receipts')->insert(['company_id' => $company, 'actor_id' => $actor->id, 'request_key' => $key, 'intent_hash' => $hash, 'result' => json_encode($result, JSON_THROW_ON_ERROR)]);

            return $result;
        });
    }
}
