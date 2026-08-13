<?php

namespace App\Models;

use App\Support\Concerns\BelongsToCompany;
use App\Support\RateType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single rate between two currencies on one date, per rate_type — see
 * docs/domain/multi-currency.md#rate-selection. Which rate applies to which
 * document (spot/average/closing) is decided by the caller, not this model.
 *
 * @property int $from_currency_id
 * @property int $to_currency_id
 * @property string $rate
 * @property Carbon $rate_date
 * @property RateType $rate_type
 * @property string|null $source
 */
class ExchangeRate extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate' => 'decimal:9',
            'rate_date' => 'date',
            'rate_type' => RateType::class,
        ];
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function fromCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function toCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
