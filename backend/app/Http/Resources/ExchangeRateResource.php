<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ExchangeRate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExchangeRateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ExchangeRate $rate */
        $rate = $this->resource;

        // whenLoaded() must wrap the resource, not the reverse (Resource::make($this
        // ->whenLoaded(...))), so Scramble's OpenAPI inference can see the field is
        // conditional and mark it optional in the generated schema instead of
        // always-present.
        return [
            'id' => $rate->id,
            'from_currency_id' => $rate->from_currency_id,
            'to_currency_id' => $rate->to_currency_id,
            // decimal(19,9) cast already returns a string — never a float, per
            // docs/architecture/api-conventions.md's money-as-string rule.
            'rate' => $rate->rate,
            'rate_date' => $rate->rate_date->toDateString(),
            'rate_type' => $rate->rate_type->value,
            'source' => $rate->source,
            'from_currency' => $this->whenLoaded('fromCurrency', fn () => new CurrencyResource($rate->fromCurrency)),
            'to_currency' => $this->whenLoaded('toCurrency', fn () => new CurrencyResource($rate->toCurrency)),
            'created_at' => $rate->created_at?->toIso8601String(),
            'updated_at' => $rate->updated_at?->toIso8601String(),
        ];
    }
}
