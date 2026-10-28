<?php

declare(strict_types=1);

namespace Tests\Support\Jobs;

use App\Models\User;
use App\Modules\Jobs\Models\ServiceJob;
use App\Modules\Jobs\Support\Execution\JobExecutionContext;
use App\Modules\Jobs\Support\Execution\JobExecutionContextVerifier;
use Illuminate\Support\Facades\DB;

/**
 * A positive TEST verifier: it proves the local behaviour of the lifecycle Actions only and certifies nothing about any Sales, Inventory or Purchasing integration. It
 * records every call (operation and whether a transaction was open), so the "source first, inside the transaction" protocol is observable.
 */
final class FakeExecutionContextVerifier implements JobExecutionContextVerifier
{
    /** @var list<array{operation: string, in_transaction: bool}> */
    public array $calls = [];

    /** @param array<string, string> $sourceVector */
    public function __construct(public bool $recordEligible = true, public bool $commandReady = true, public array $sourceVector = ['release' => 'v1']) {}

    public function enter(User $actor, ServiceJob $job, string $operation): JobExecutionContext
    {
        $this->calls[] = ['operation' => $operation, 'in_transaction' => DB::transactionLevel() > 0];

        return new JobExecutionContext($this->sourceVector, $this->recordEligible, $this->commandReady);
    }
}
