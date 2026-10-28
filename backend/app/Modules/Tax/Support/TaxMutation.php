<?php

declare(strict_types=1);

namespace App\Modules\Tax\Support;

use App\Modules\Tax\Models\TaxOperationReceipt;
use App\Support\CompanyContext;
use Closure;
use Illuminate\Support\Facades\DB;

/** Internal transaction protocol for the guarded Tax Actions only. Same lock as every database writer. */
final class TaxMutation
{
    /**
     * @param  array<string, mixed>  $intent
     * @param  Closure(): array<string, int>  $apply
     * @return array<string, int>
     */
    public function run(string $key, int $expectedGeneration, array $intent, Closure $apply): array
    {
        if (trim($key) === '' || strlen($key) > 120) {
            throw TaxRefusal::because('TAX_KEY_INVALID', 'A bounded operation key is required.');
        }
        $digest = hash('sha256', json_encode($intent, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($key, $expectedGeneration, $digest, $apply): array {
            $companyId = app(CompanyContext::class)->id();
            DB::table('tax_generations')->insertOrIgnore(['company_id' => $companyId, 'generation' => 0]);
            $row = DB::table('tax_generations')->where('company_id', $companyId)->lockForUpdate()->first();
            $receipt = TaxOperationReceipt::query()->where('operation_key', $key)->first();
            if ($receipt !== null) {
                if ($receipt->getAttribute('intent_digest') !== $digest) {
                    throw TaxRefusal::because('TAX_KEY_CONFLICT', 'The operation key belongs to another intent.');
                }
                /** @var array<string, int> $outcome */
                $outcome = $receipt->getAttribute('outcome');

                return $outcome;
            }
            if ((int) $row->generation !== $expectedGeneration) {
                throw TaxRefusal::because('TAX_GENERATION_STALE', 'Tax configuration changed; reload before writing.');
            }
            $outcome = $apply();
            TaxOperationReceipt::create(['operation_key' => $key, 'intent_digest' => $digest, 'outcome' => $outcome]);

            return $outcome;
        });
    }
}
