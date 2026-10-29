<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The immutable record of one issued quotation revision (ADR 0123): the reviewed input generation, the maker (the revision's creator) and the checker (the issuer), the
 * pinned evidence references, the settled document totals and a fingerprint of the frozen lines. Exactly one per issued revision; append-only. The money columns are
 * settled storage amounts, never raw fractions; withholding / payment is not here (the policy is BLOCK: an unknown payment estimate blocks issue).
 *
 * @property int $family_id
 * @property int $revision_id
 * @property int $reviewed_generation
 * @property int $revision_created_by
 * @property int $issued_by
 * @property Carbon $issued_at
 * @property string $official_number
 * @property int $numbering_allocation_id
 * @property string $policy_version
 * @property string $unknown_payment_policy
 * @property int $currency_id
 * @property Carbon $pricing_date
 * @property Carbon|null $tax_estimate_date
 * @property Carbon $valid_through_local_date
 * @property string $issued_timezone
 * @property Carbon $expires_at
 * @property string $net_total
 * @property string|null $optional_net_total
 * @property string|null $tax_total
 * @property string|null $gross_total
 * @property string|null $rounding_policy_fingerprint
 * @property int|null $rounding_policy_version_no
 * @property string|null $tax_calculation_version
 * @property int|null $tax_generation
 * @property string|null $tax_evidence_fingerprint
 * @property string $controls_outcome
 * @property string|null $controls_fingerprint
 * @property string $financial_fingerprint
 */
class QuotationIssue extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'quotation_issues';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'expires_at' => 'datetime', 'pricing_date' => 'date', 'tax_estimate_date' => 'date', 'valid_through_local_date' => 'date',
            'net_total' => 'decimal:4', 'optional_net_total' => 'decimal:4', 'tax_total' => 'decimal:4', 'gross_total' => 'decimal:4'];
    }

    /** @return HasMany<QuotationIssuedLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(QuotationIssuedLine::class, 'issue_id')->orderBy('sort_order')->orderBy('id');
    }
}
