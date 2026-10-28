<?php

declare(strict_types=1);

namespace App\Modules\Tax\Support\Quotation;

use App\Modules\Tax\Support\TaxDisposition;
use App\Modules\Tax\Support\TaxSupport;
use JsonSerializable;

/** Internal, read-only evidence: not an issue/adoption token and never a profile projection. */
final readonly class QuotationTaxDetermination implements JsonSerializable
{
    public TaxSupport $support;

    /** @param array<string, int|string|null> $pins */
    public function __construct(public string $lineReference, public TaxDisposition $disposition, public string $reason,
        private array $pins, private ?string $percentage = null)
    {
        $this->support = $disposition !== TaxDisposition::Unknown ? TaxSupport::Supported :
            (in_array($reason, ['LINE_NATURE_AMBIGUOUS', 'TAX_CALCULATION_UNSUPPORTED'], true) ? TaxSupport::Unsupported : TaxSupport::Unknown);
    }

    /** Safe consumer projection excludes rule/rate/profile identities and protected grounds. @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['support' => $this->support->value, 'line_reference' => $this->lineReference, 'disposition' => $this->disposition->value, 'reason' => $this->disposition === TaxDisposition::Unknown ? 'TAX_CONTEXT_UNKNOWN' : 'TAX_DETERMINED',
            'evidence_reference' => $this->fingerprint(), 'issue_ready' => false];
    }

    /** Trusted server consumer only; no evidence text, registration, issuer or certificate is ever copied here.
     * @return array<string, int|string|null> */
    public function internalEvidence(): array
    {
        return $this->pins + ['percentage' => $this->percentage];
    }

    public function fingerprint(): string
    {
        $facts = $this->pins;
        unset($facts['captured_at']);

        return hash('sha256', json_encode([$this->lineReference, $this->disposition->value, $this->reason, $facts, $this->percentage], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }
}
