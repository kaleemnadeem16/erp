<?php

declare(strict_types=1);

namespace App\Modules\Tax\Support\Quotation;

use App\Models\Currency;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Support\Financial\CurrencyQuantum;
use App\Modules\Tax\Actions\DetermineQuotationTax;
use App\Modules\Tax\Models\TaxGroupVersion;
use App\Modules\Tax\Models\TaxPartyComponentEvidence;
use App\Modules\Tax\Models\TaxPartyProfileVersion;
use App\Modules\Tax\Models\TaxQuotationDeclarationRule;
use App\Modules\Tax\Models\TaxQuotationDeclarationVersion;
use App\Modules\Tax\Models\TaxRateVersion;
use App\Modules\Tax\Models\TaxRuleVersion;
use App\Modules\Tax\Support\EffectivePeriod;
use App\Modules\Tax\Support\TaxDisposition;
use App\Modules\Tax\Support\TaxRefusal;
use App\Modules\Tax\Support\TaxSupport;
use App\Support\Status;
use Illuminate\Support\Facades\DB;

/** @internal Only the guarded context Action may call this protected read helper. */
final class QuotationTaxDeclarationResolver
{
    public function resolve(QuotationTaxContext $context): QuotationTaxDetermination
    {
        $generation = (int) DB::table('tax_generations')->where('company_id', $context->companyId)->value('generation');
        $pins = ['company_id' => $context->companyId, 'context_fingerprint' => $context->fingerprint(), 'tax_generation' => $generation,
            'currency_id' => $context->currencyId, 'jurisdiction' => $context->jurisdiction, 'tax_date' => $context->taxDate,
            'classification' => $context->classification->code, 'nature' => $context->classification->nature?->value,
            'classification_provenance' => $context->classification->provenance, 'source_kind' => $context->classification->source->value,
            'calculation_version' => 'FR3.1', 'captured_at' => now()->toIso8601String()];
        $result = $this->determine($context, $pins);
        if ((int) DB::table('tax_generations')->where('company_id', $context->companyId)->value('generation') !== $generation) {
            throw TaxRefusal::because('TAX_CONTEXT_STALE', 'Tax evidence changed during determination; determine it again.');
        }

        return $result;
    }

    /** @param array<string,int|string|null> $pins */
    private function determine(QuotationTaxContext $context, array $pins): QuotationTaxDetermination
    {
        $currency = Currency::query()->find($context->currencyId);
        if ($currency === null) {
            return $this->unknown($context, 'TAX_CURRENCY_UNAVAILABLE', $pins);
        }
        $pins['currency_places'] = CurrencyQuantum::forCurrency($currency)->decimalPlaces;
        $classification = (new DetermineQuotationTax)($context->classification);
        if ($classification->support !== TaxSupport::Supported) {
            return $this->unknown($context, $classification->reason, $pins);
        }
        if (! Party::query()->whereKey($context->partyId)->where('status', Status::Active->value)->exists()) {
            return $this->unknown($context, 'TAX_CONTEXT_UNAVAILABLE', $pins);
        }
        $versions = TaxQuotationDeclarationVersion::query()->where('jurisdiction', $context->jurisdiction)
            ->where('effective_from', '<=', $context->taxDate)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $context->taxDate))->get();
        if ($versions->count() !== 1) {
            return $this->unknown($context, 'TAX_DECLARATION_UNAVAILABLE', $pins);
        }
        $version = $versions->firstOrFail();
        $activation = DB::table('tax_quotation_declaration_activations')->where('company_id', $context->companyId)->where('declaration_version_id', $version->getKey())->value('id');
        if ($activation === null) {
            return $this->unknown($context, 'TAX_DECLARATION_UNAVAILABLE', $pins);
        }
        $pins += ['declaration_version_id' => (int) $version->getKey(), 'declaration_version_no' => (int) $version->getAttribute('version_no'),
            'declaration_activation_id' => (int) $activation, 'settlement_basis' => (string) $version->getAttribute('settlement_basis'), 'rounding_mode' => (string) $version->getAttribute('rounding_mode')];
        $rules = TaxQuotationDeclarationRule::query()->where('declaration_version_id', $version->getKey())
            ->where('nature', $context->classification->nature?->value)->where('classification', $context->classification->code)->get();
        if ($rules->count() !== 1) {
            return $this->unknown($context, 'TAX_CLASSIFICATION_NOT_COVERED', $pins);
        }
        $declaration = $rules->firstOrFail();
        $group = TaxGroupVersion::query()->findOrFail((int) $declaration->getAttribute('group_version_id'));
        $rule = TaxRuleVersion::query()->findOrFail((int) $declaration->getAttribute('rule_version_id'));
        $rate = TaxRateVersion::query()->findOrFail((int) $rule->getAttribute('rate_version_id'));
        // Activation proves structure/periods; repeat supported method and currency at use.
        if ($group->getAttribute('required_mode') !== 'BASIC' || $rule->getAttribute('method') !== 'PERCENT' || $rule->getAttribute('base') !== 'NET'
            || (int) $group->getAttribute('currency_id') !== $context->currencyId || $rate->getAttribute('percentage') === null) {
            return $this->unknown($context, 'TAX_CALCULATION_UNSUPPORTED', $pins);
        }
        foreach ([$group, $rule, $rate] as $evidence) {
            if (! (new EffectivePeriod((string) $evidence->getAttribute('effective_from'), $evidence->getAttribute('effective_to')))->covers($context->taxDate)) {
                return $this->unknown($context, 'TAX_EVIDENCE_EXPIRED', $pins);
            }
        }
        $pins += ['declaration_rule_id' => (int) $declaration->getKey(), 'component_id' => (int) $declaration->getAttribute('component_id'),
            'component_version_id' => (int) $rule->getAttribute('component_version_id'), 'group_version_id' => (int) $group->getKey(),
            'rule_version_id' => (int) $rule->getKey(), 'rate_version_id' => (int) $rate->getKey()];
        $disposition = TaxDisposition::from((string) $declaration->getAttribute('disposition'));
        $profiles = TaxPartyProfileVersion::query()->where('party_id', $context->partyId)->where('jurisdiction', $context->jurisdiction)
            ->where('effective_from', '<=', $context->taxDate)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $context->taxDate))->get();
        if ($profiles->count() > 1) {
            return $this->unknown($context, 'TAX_PROFILE_UNAVAILABLE', $pins);
        }
        $profile = $profiles->first();
        $profileFact = null;
        if ($profile !== null) {
            $profileActivation = DB::table('tax_party_profile_activations')->where('company_id', $context->companyId)->where('profile_version_id', $profile->getKey())->value('id');
            if ($profileActivation === null) {
                return $this->unknown($context, 'TAX_PROFILE_UNAVAILABLE', $pins);
            }
            $profileFact = TaxPartyComponentEvidence::query()->where('profile_version_id', $profile->getKey())->where('component_id', $declaration->getAttribute('component_id'))->first();
            if ($profileFact === null) {
                return $this->unknown($context, 'TAX_PROFILE_COVERAGE_UNKNOWN', $pins);
            }
            $pins += ['profile_version_id' => (int) $profile->getKey(), 'profile_activation_id' => (int) $profileActivation, 'profile_evidence_id' => (int) $profileFact->getKey()];
            $issued = $profileFact->getAttribute('issued_on');
            $expires = $profileFact->getAttribute('expires_on');
            if (($issued !== null && $issued > $context->taxDate) || ($expires !== null && $expires <= $context->taxDate)) {
                return $this->unknown($context, 'TAX_EVIDENCE_EXPIRED', $pins);
            }
        }
        if ((bool) $declaration->getAttribute('requires_party_profile')) {
            if ($profileFact === null) {
                return $this->unknown($context, 'TAX_PROFILE_UNAVAILABLE', $pins);
            }
            $disposition = TaxDisposition::from((string) $profileFact->getAttribute('disposition'));
        } elseif ($profileFact !== null && $profileFact->getAttribute('disposition') !== $disposition->value) {
            // A broad company declaration cannot suppress contrary/unknown Party evidence.
            return $this->unknown($context, 'TAX_DECLARATION_CONFLICT', $pins);
        }
        if ($disposition === TaxDisposition::Unknown) {
            return $this->unknown($context, 'TAX_DISPOSITION_UNKNOWN', $pins);
        }

        return new QuotationTaxDetermination($context->lineReference, $disposition, 'TAX_DETERMINED', $pins, (string) $rate->getAttribute('percentage'));
    }

    /** @param array<string,int|string|null> $pins */
    private function unknown(QuotationTaxContext $context,string $reason,array $pins): QuotationTaxDetermination
    {
        return new QuotationTaxDetermination($context->lineReference,TaxDisposition::Unknown,$reason,$pins);
    }
}
