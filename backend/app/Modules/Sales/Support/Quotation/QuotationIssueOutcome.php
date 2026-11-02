<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation;

/** What an issue reports: the revision, its one official number and the database's instant of issue. A replay reports the original, with `replayed` set. Nothing monetary. */
final readonly class QuotationIssueOutcome
{
    public function __construct(public int $familyId, public int $revisionId, public int $issueId, public string $officialNumber, public string $issuedAt, public bool $replayed) {}

    /** @param  array<string, mixed>  $result */
    public static function fromReceipt(array $result, bool $replayed): self
    {
        return new self((int) $result['family_id'], (int) $result['revision_id'], (int) $result['issue_id'], (string) $result['official_number'], (string) $result['issued_at'], $replayed);
    }

    /** @return array{v: int, family_id: int, revision_id: int, issue_id: int, official_number: string, issued_at: string} */
    public static function result(int $familyId, int $revisionId, int $issueId, string $officialNumber, string $issuedAt): array
    {
        return ['v' => 1, 'family_id' => $familyId, 'revision_id' => $revisionId, 'issue_id' => $issueId, 'official_number' => $officialNumber, 'issued_at' => $issuedAt];
    }
}
