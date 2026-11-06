<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\DiscountAuthority;

use App\Models\User;
use App\Modules\Sales\Models\QuotationRevision;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;

/** @internal Persistence collaborator of guarded Sales operations; never a public authority boundary. */
final class AuthorityEvaluationStore
{
    /** @param array<string, mixed> $assessment */
    public function record(User $actor, QuotationRevision $revision, string $mode, array $assessment): int
    {
        return DB::table('sales_discount_evaluations')->insertGetId([
            'company_id' => $revision->company_id, 'revision_id' => $revision->id, 'input_generation' => $revision->input_generation,
            'actor_id' => $actor->id, 'authority_generation' => $assessment['generation'], 'policy_id' => $assessment['policy_id'], 'mode' => $mode,
            'result' => $assessment['result'], 'transaction_currency_id' => $revision->currency_id,
            'base_currency_id' => app(CurrentCompany::class)->baseCurrencyId(),
            'reference_amount' => $assessment['measure']['reference_amount'] ?? null, 'reduction_amount' => $assessment['measure']['reduction_amount'] ?? null,
            'reference_base_amount' => $assessment['measure']['reference_base_amount'] ?? null, 'final_net_amount' => $assessment['measure']['final_net_amount'] ?? null,
            'final_net_base_amount' => $assessment['measure']['final_net_base_amount'] ?? null,
            'reduction_base_amount' => $assessment['measure']['reduction_base_amount'] ?? null,
            'snapshot' => json_encode($assessment, JSON_THROW_ON_ERROR), 'fingerprint' => $assessment['fingerprint'],
        ]);
    }
}
