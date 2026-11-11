<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Support\Handover\HandoverAccess;
use App\Modules\Sales\Support\Handover\HandoverInput;
use App\Modules\Sales\Support\Handover\ServiceHandoverAssessment;
use Illuminate\Support\Facades\DB;

/** Current source-specific prerequisites with owning next actions, distinct from valid commercial consent and from release. */
final class AssessAcceptanceReadiness
{
    public function __construct(private readonly HandoverAccess $access, private readonly ServiceHandoverAssessment $assessment) {}

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function __invoke(User $actor, int $acceptanceId, string $path = 'SERVICE_JOB', array $context = []): array
    {
        HandoverAccess::early($actor, false);
        $inputs = HandoverInput::normalise(array_replace($context, ['path' => $path]));

        return DB::transaction(function () use ($actor, $acceptanceId, $inputs): array {
            $s = $this->access->lock($actor, $acceptanceId, false);

            return $this->assessment->assess($s, $inputs)['assessment'];
        });
    }
}
