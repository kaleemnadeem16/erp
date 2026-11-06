<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation\Approval;

/** What an approval operation reports: the revision, the shared approval request and its status, at the draft generation the operation judged. A replay reports the original. Nothing monetary. */
final readonly class QuotationApprovalOutcome
{
    public function __construct(public int $familyId, public int $revisionId, public int $approvalRequestId, public string $status, public int $inputGeneration, public bool $replayed) {}

    /** @param  array<string, mixed>  $result */
    public static function fromReceipt(array $result, bool $replayed): self
    {
        return new self((int) $result['family_id'], (int) $result['revision_id'], (int) $result['approval_request_id'], (string) $result['status'], (int) $result['input_generation'], $replayed);
    }

    /** @return array{v: int, family_id: int, revision_id: int, approval_request_id: int, status: string, input_generation: int} */
    public static function result(int $familyId, int $revisionId, int $approvalRequestId, string $status, int $inputGeneration): array
    {
        return ['v' => 1, 'family_id' => $familyId, 'revision_id' => $revisionId, 'approval_request_id' => $approvalRequestId, 'status' => $status, 'input_generation' => $inputGeneration];
    }
}
