<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Support;

/**
 * What a subject tells the engine about a document at one moment: the facts a policy routes on, the amount
 * in the company base currency with its pinned conversion evidence, and a summary for the approver's inbox.
 *
 * `hash()` covers the facts and the base amount and nothing else. An approval is honoured only while the
 * subject's *current* routing hashes to what was approved (ADR 0070 §8), so a changed document can never
 * ride on an approval given to an earlier version of it.
 */
final class ApprovalRouting
{
    /**
     * @param  array<string, string|int|null>  $facts  fact key → value, as declared by the subject type
     * @param  string|null  $amountBase  the routed amount in the company base currency (decimal string), or null
     * @param  array<string, mixed>  $evidence  original amount/currency, rate, rate date and source, converted amount
     * @param  array<string, mixed>  $summary  human labels for the inbox (number, title, party…)
     */
    public function __construct(
        public readonly array $facts,
        public readonly ?string $amountBase,
        public readonly array $evidence,
        public readonly array $summary,
    ) {}

    public function hash(): string
    {
        $facts = $this->facts;
        ksort($facts);

        return hash('sha256', json_encode(['facts' => $facts, 'amount_base' => $this->amountBase], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return ['facts' => $this->facts, 'amount_base' => $this->amountBase, 'evidence' => $this->evidence];
    }
}
