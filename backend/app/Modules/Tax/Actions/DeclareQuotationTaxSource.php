<?php

declare(strict_types=1);

namespace App\Modules\Tax\Actions;

use App\Models\User;
use App\Modules\Tax\Support\EffectivePeriod;
use App\Modules\Tax\Support\TaxAccess;
use App\Modules\Tax\Support\TaxMutation;
use App\Modules\Tax\Support\TaxNature;
use App\Modules\Tax\Support\TaxRefusal;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

/** Explicit, append-only source declarations; no inferred jurisdiction or classification. */
final class DeclareQuotationTaxSource
{
    public function __construct(private readonly TaxMutation $mutation) {}

    public function jurisdiction(User $actor, string $jurisdiction, EffectivePeriod $period, string $reason, string $key, int $generation): int
    {
        TaxAccess::requireConfiguration($actor);
        $jurisdiction = trim($jurisdiction);
        $reason = trim($reason);
        if ($jurisdiction === '' || mb_strlen($jurisdiction) > 80 || $reason === '') {
            throw TaxRefusal::because('TAX_SOURCE_INVALID', 'A jurisdiction and reason are required.');
        }

        $outcome = $this->mutation->run($key, $generation, ['operation' => 'quotation_jurisdiction', 'actor' => $actor->id, 'jurisdiction' => $jurisdiction,
            'from' => $period->from, 'to' => $period->endExclusive, 'reason' => $reason], fn (): array => ['id' => (int) DB::table('tax_quotation_jurisdictions')->insertGetId([
                'company_id' => app(CompanyContext::class)->id(), 'jurisdiction' => $jurisdiction, 'effective_from' => $period->from,
                'effective_to' => $period->endExclusive, 'created_by' => $actor->id, 'reason' => $reason,
            ])]);

        return $outcome['id'];
    }

    public function item(User $actor, int $itemId, TaxNature $nature, string $classification, EffectivePeriod $period, string $reason, string $key, int $generation): int
    {
        TaxAccess::requireConfiguration($actor);
        $classification = trim($classification);
        $reason = trim($reason);
        if ($itemId < 1 || $nature === TaxNature::Both || $classification === '' || mb_strlen($classification) > 80 || $reason === '') {
            throw TaxRefusal::because('TAX_SOURCE_INVALID', 'An item, single nature, classification and reason are required.');
        }

        $outcome = $this->mutation->run($key, $generation, ['operation' => 'quotation_item_classification', 'actor' => $actor->id, 'item' => $itemId,
            'nature' => $nature->value, 'classification' => $classification, 'from' => $period->from, 'to' => $period->endExclusive, 'reason' => $reason],
            fn (): array => ['id' => (int) DB::table('tax_quotation_item_classifications')->insertGetId([
                'company_id' => app(CompanyContext::class)->id(), 'item_id' => $itemId, 'nature' => $nature->value, 'classification' => $classification,
                'effective_from' => $period->from, 'effective_to' => $period->endExclusive, 'created_by' => $actor->id, 'reason' => $reason,
            ])]);

        return $outcome['id'];
    }
}
