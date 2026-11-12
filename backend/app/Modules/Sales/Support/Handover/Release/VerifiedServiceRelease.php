<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Handover\Release;

use LogicException;

/**
 * Everything the Jobs receiver may trust about one release, resolved by Sales under the real source fences and inside the release transaction: canonical company,
 * acceptance/line/stage/baseline identities, the pinned completion conditions and the permitted operational fields. It can only be issued by {@see ReleaseCoordinator}; it
 * carries no authority flag and no money, cost, hash or private evidence. The receiver must call `assertCurrent()` and still independently require `job.receive`.
 */
final readonly class VerifiedServiceRelease
{
    /**
     * @param  array<string,mixed>  $operational  location kind, instructions, contact and site as pinned in the stage baseline
     */
    private function __construct(
        public int $companyId,
        public int $releaseEventId,
        public int $stageId,
        public int $baselineId,
        public int $acceptanceId,
        public int $acceptanceLineId,
        public int $issuedLineId,
        public string $purpose,
        public string $deliverableIdentity,
        public string $deliverableText,
        public ResolvedCompletionBasis $basis,
        public array $operational,
        public int $actorId,
        public HandoverTransactionContext $context,
    ) {}

    /**
     * @param  array<string,mixed>  $operational
     *
     * @internal only ReleaseCoordinator may issue it; a different caller is refused
     */
    public static function issue(int $companyId, int $releaseEventId, int $stageId, int $baselineId, int $acceptanceId, int $acceptanceLineId, int $issuedLineId, string $purpose,
        string $deliverableIdentity, string $deliverableText, ResolvedCompletionBasis $basis, array $operational, int $actorId, HandoverTransactionContext $context): self
    {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['class'] ?? null;
        if ($caller !== ReleaseCoordinator::class) {
            throw new LogicException('A verified service release is issued only by the release coordinator.');
        }
        $context->assertCurrent();

        return new self($companyId, $releaseEventId, $stageId, $baselineId, $acceptanceId, $acceptanceLineId, $issuedLineId, $purpose, $deliverableIdentity, $deliverableText, $basis, $operational, $actorId, $context);
    }

    /** @throws LogicException when the caller is not inside the release's own connection, session and transaction */
    public function assertCurrent(): void
    {
        $this->context->assertCurrent();
    }
}
