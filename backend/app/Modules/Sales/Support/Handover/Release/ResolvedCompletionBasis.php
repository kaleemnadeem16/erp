<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Handover\Release;

/**
 * The customer-agreed completion conditions of one accepted issued line, as pinned for a release: the exact deliverable text (the issued line, verbatim), the exact
 * agreed conditions (the head `service_completion_bases` version), the consent evidence it cites and the attestation policy the Job completes under.
 * Safe to hand to the Jobs receiver: no money, no cost, no private evidence content.
 */
final readonly class ResolvedCompletionBasis
{
    public function __construct(
        public int $basisId,
        public int $companyId,
        public int $issuedLineId,
        public int $version,
        public string $deliverableText,
        public string $agreedText,
        public int $evidenceId,
        public string $attestationPolicy,
        public string $termsSource = 'ISSUED_LINE',
        public string $materialsScope = 'UNSTATED',
        public string $subcontractScope = 'UNSTATED',
    ) {}

    /** Stable identity of exactly this basis version; the same string the database release guard requires. */
    public function conditionIdentity(): string
    {
        return 'completion-basis:'.$this->companyId.':'.$this->basisId.':v'.$this->version;
    }

    /** True only for a basis that carries its OWN customer-agreement evidence (a scope declaration is never accepted on the issued-line path). */
    public function declaresScope(): bool
    {
        return $this->termsSource === 'EVIDENCE_TEXT';
    }

    public function provenance(): string
    {
        return 'consent-evidence:'.$this->evidenceId;
    }
}
