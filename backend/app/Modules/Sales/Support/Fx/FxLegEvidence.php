<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Fx;

/**
 * One captured currency→base leg: the rate value, the date it is actually dated and how old that is against the requested date, copied so that a later
 * edit or delete of the master rate cannot change what an offer was based on. An identity leg (a currency to itself) is explicit, with rate one, and
 * never stands in for a missing rate.
 */
final readonly class FxLegEvidence
{
    public function __construct(
        public int $fromCurrencyId,
        public int $toCurrencyId,
        public string $rate,
        public string $requestedDate,
        public string $actualDate,
        public int $ageDays,
        public bool $identity,
        public ?string $source,
        public ?int $rateMasterId,
    ) {}

    public static function identity(int $currencyId, string $date): self
    {
        return new self($currencyId, $currencyId, '1.000000000', $date, $date, 0, true, 'IDENTITY', null);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['pair' => [$this->fromCurrencyId, $this->toCurrencyId], 'rate_type' => 'SPOT', 'rate' => $this->rate, 'requested_date' => $this->requestedDate,
            'actual_date' => $this->actualDate, 'age_days' => $this->ageDays, 'identity' => $this->identity, 'source' => $this->source, 'rate_master_id' => $this->rateMasterId];
    }
}
