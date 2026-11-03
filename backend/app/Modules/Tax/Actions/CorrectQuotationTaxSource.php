<?php

declare(strict_types=1);

namespace App\Modules\Tax\Actions;

use App\Models\User;
use App\Modules\Tax\Support\TaxAccess;
use App\Modules\Tax\Support\TaxMutation;
use App\Modules\Tax\Support\TaxNature;
use App\Modules\Tax\Support\TaxRefusal;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * Corrects a quotation tax source fact (a jurisdiction, or an Item classification) as a NEW immutable version, never an edit: the original fact and every earlier correction stay as
 * evidence and the reader uses the latest version for the fact's own period. Guarded by the staged `tax.configuration.manage` duty (company-wide scope), generation-fenced and
 * idempotent through the same Tax mutation protocol as every Tax writer; the correction bumps the Tax generation so a determination made before it is provably stale.
 * A fact of another company is indistinguishable from a missing one.
 */
final class CorrectQuotationTaxSource
{
    public function __construct(private readonly TaxMutation $mutation) {}

    public function jurisdiction(User $actor, int $factId, string $jurisdiction, string $reason, string $key, int $generation): int
    {
        TaxAccess::requireConfiguration($actor);
        $jurisdiction = trim($jurisdiction);
        $reason = trim($reason);
        if ($factId < 1 || $jurisdiction === '' || mb_strlen($jurisdiction) > 80 || $reason === '') {
            throw TaxRefusal::because('TAX_SOURCE_INVALID', 'A jurisdiction fact, the corrected jurisdiction and a reason are required.');
        }

        return $this->correct($actor, 'jurisdiction_fact_id', 'tax_quotation_jurisdictions', $factId, ['jurisdiction' => $jurisdiction], $reason, $key, $generation);
    }

    public function item(User $actor, int $factId, TaxNature $nature, string $classification, string $reason, string $key, int $generation): int
    {
        TaxAccess::requireConfiguration($actor);
        $classification = trim($classification);
        $reason = trim($reason);
        if ($factId < 1 || $nature === TaxNature::Both || $classification === '' || mb_strlen($classification) > 80 || $reason === '') {
            throw TaxRefusal::because('TAX_SOURCE_INVALID', 'An Item classification fact, a single nature, the corrected classification and a reason are required.');
        }

        return $this->correct($actor, 'classification_fact_id', 'tax_quotation_item_classifications', $factId, ['nature' => $nature->value, 'classification' => $classification], $reason, $key, $generation);
    }

    /** @param array<string, string> $values */
    private function correct(User $actor, string $column, string $table, int $factId, array $values, string $reason, string $key, int $generation): int
    {
        $outcome = $this->mutation->run($key, $generation, ['operation' => 'correct_quotation_source', 'actor' => $actor->id, 'target' => $column, 'fact' => $factId, 'values' => $values, 'reason' => $reason],
            function () use ($actor, $column, $table, $factId, $values, $reason): array {
                $company = app(CompanyContext::class)->id();
                if (! DB::table($table)->where('company_id', $company)->where('id', $factId)->exists()) {
                    throw TaxRefusal::because('TAX_SOURCE_INVALID', 'That tax source fact was not found.');
                }
                $latest = DB::table('tax_quotation_source_corrections')->where('company_id', $company)->where($column, $factId)->orderByDesc('version_no')->first();
                $same = $latest !== null && collect($values)->every(fn (string $value, string $field): bool => (string) $latest->{$field} === $value);
                if ($same) {
                    throw TaxRefusal::because('TAX_SOURCE_UNCHANGED', 'The correction states what the latest version already says.');
                }

                return ['id' => (int) DB::table('tax_quotation_source_corrections')->insertGetId([
                    'company_id' => $company, $column => $factId, 'version_no' => $latest === null ? 1 : (int) $latest->version_no + 1, 'predecessor_id' => $latest?->id,
                    'reason' => $reason, 'created_by' => $actor->id,
                ] + $values)];
            });

        return $outcome['id'];
    }
}
