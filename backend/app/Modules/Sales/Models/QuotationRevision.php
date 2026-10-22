<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Currency;
use App\Models\Model;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Support\Quotation\QuotationRevisionState;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One explicit revision of a family. Today it is a DRAFT (editable, possibly incomplete, governs nothing) or a CANCELLED_DRAFT (terminal, immutable). The
 * ordinal is allocated by the database under the family lock and the input generation advances with every material change, so a stale reader can tell.
 * The recipient is a Party — a prospect with no CustomerAccount is a legitimate recipient. There is no price, tax or number here (see the migration).
 *
 * @property int $id
 * @property int $family_id
 * @property int $revision_ordinal
 * @property string $state
 * @property int $input_generation
 * @property int|null $recipient_party_id
 * @property int $currency_id
 * @property Carbon|null $pricing_date
 * @property Carbon|null $tax_estimate_date
 * @property Carbon|null $valid_through_local_date
 * @property int|null $predecessor_revision_id
 * @property string|null $rounding_policy_fingerprint the rounding policy the entered prices were prepared under (null while no price is entered)
 * @property int|null $rounding_policy_version_no
 * @property Carbon|null $rounding_policy_as_of
 */
class QuotationRevision extends Model
{
    use BelongsToCompany;

    protected $table = 'quotation_revisions';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['pricing_date' => 'date', 'tax_estimate_date' => 'date', 'valid_through_local_date' => 'date', 'cancelled_at' => 'datetime', 'rounding_policy_as_of' => 'date'];
    }

    public function isDraft(): bool
    {
        return $this->state === QuotationRevisionState::Draft->value;
    }

    /** @return BelongsTo<QuotationFamily, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(QuotationFamily::class, 'family_id');
    }

    /** @return BelongsTo<Party, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'recipient_party_id');
    }

    /** @return BelongsTo<Currency, $this> */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /** @return BelongsTo<self, $this> */
    public function predecessor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'predecessor_revision_id');
    }

    /** @return HasMany<QuotationDraftLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(QuotationDraftLine::class, 'revision_id')->orderBy('sort_order')->orderBy('id');
    }
}
