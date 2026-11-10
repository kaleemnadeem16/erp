<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\DiscountAuthority;

use App\Modules\Sales\Models\QuotationRevision;
use Illuminate\Support\Facades\DB;

/** @internal Provenance includes actual commercial editors, not just the last person to enter a discount. */
final class AuthorityParticipants
{
    /** @return list<int> */
    public function ids(QuotationRevision $revision): array
    {
        $ids = [$revision->created_by];
        foreach ($revision->lines()->get(['created_by', 'discount_entered_by', 'override_entered_by']) as $line) {
            foreach (['created_by', 'discount_entered_by', 'override_entered_by'] as $field) {
                if ($line->getAttribute($field) !== null) {
                    $ids[] = (int) $line->getAttribute($field);
                }
            }
        }
        foreach (DB::table('sales_quotation_operation_receipts')->where('company_id', $revision->company_id)->where('revision_id', $revision->id)
            ->whereIn('operation', ['quotation.create_draft', 'quotation.create_revision', 'quotation.update_draft'])->pluck('actor_id') as $id) {
            $ids[] = (int) $id;
        }
        foreach (DB::table('quotation_concession_participants')->where('company_id', $revision->company_id)->where('revision_id', $revision->id)->pluck('actor_id') as $id) {
            $ids[] = (int) $id;
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /** @return array<int, int> */
    public function epochs(QuotationRevision $revision): array
    {
        return DB::table('users')->where('company_id', $revision->company_id)->whereIn('id', $this->ids($revision))->orderBy('id')
            ->pluck('authorization_generation', 'id')->map(fn ($generation): int => (int) $generation)->all();
    }
}
