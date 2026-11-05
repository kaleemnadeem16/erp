<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation\Approval;

use App\Modules\Sales\Models\QuotationRevision;
use Illuminate\Support\Facades\DB;

/**
 * The exact draft content an approval is given for (ADR 0158): every field that prices, reduces or identifies what the customer would be offered, read from the stored
 * draft (never from a preview, so it needs no actor and no live evidence), together with the draft's input generation. Any material edit moves the generation, and any
 * edit of a priced or conceded field moves the content, so an approval can never ride on a draft it was not given for. No cost, limit or margin is ever part of it.
 */
final class QuotationContentFingerprint
{
    public const VERSION = 'QAF.1';

    public static function of(QuotationRevision $revision): string
    {
        $lines = DB::table('quotation_draft_lines')->where('revision_id', $revision->id)->orderBy('id')->get([
            'id', 'logical_scope_id', 'sort_order', 'description', 'membership', 'choice_group_id', 'is_baseline', 'source_kind', 'item_id', 'assembly_version_id', 'manual_measure',
            'offered_quantity', 'offered_uom_id', 'unit_price', 'price_currency_id', 'fx_fingerprint', 'discount_kind', 'discount_value', 'net_override', 'override_reason',
            'approval_required', 'approval_reason',
        ])->map(fn ($line): array => (array) $line)->all();
        $document = DB::table('quotation_draft_document_discounts')->where('revision_id', $revision->id)->first(['kind', 'value', 'approval_required', 'approval_reason']);

        return hash('sha256', json_encode([
            'v' => self::VERSION, 'revision' => $revision->id, 'generation' => (int) DB::table('quotation_revisions')->where('id', $revision->id)->value('input_generation'),
            'header' => [
                'recipient' => $revision->recipient_party_id, 'currency' => $revision->currency_id, 'pricing_date' => $revision->pricing_date?->format('Y-m-d'),
                'rounding' => $revision->rounding_policy_fingerprint,
            ],
            'lines' => $lines, 'document_discount' => $document === null ? null : (array) $document,
        ], JSON_THROW_ON_ERROR));
    }

    /** DISCOUNT, OVERRIDE, both joined by a plus, or NONE: which kinds of concession the stored draft carries. */
    public static function concessions(QuotationRevision $revision): string
    {
        $kinds = [];
        $lines = DB::table('quotation_draft_lines')->where('revision_id', $revision->id);
        if ((clone $lines)->whereNotNull('discount_kind')->exists() || DB::table('quotation_draft_document_discounts')->where('revision_id', $revision->id)->exists()) {
            $kinds[] = 'DISCOUNT';
        }
        if ((clone $lines)->whereNotNull('net_override')->exists()) {
            $kinds[] = 'OVERRIDE';
        }

        return $kinds === [] ? 'NONE' : implode('+', $kinds);
    }
}
