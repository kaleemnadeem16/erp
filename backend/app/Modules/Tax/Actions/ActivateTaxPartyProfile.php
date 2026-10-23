<?php

declare(strict_types=1);

namespace App\Modules\Tax\Actions;

use App\Models\User;
use App\Modules\Tax\Models\TaxPartyProfileActivation;
use App\Modules\Tax\Models\TaxPartyProfileVersion;
use App\Modules\Tax\Support\TaxAccess;
use App\Modules\Tax\Support\TaxMutation;
use App\Modules\Tax\Support\TaxRefusal;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;

/** Stores reviewed configuration evidence only; never enables statutory determination. */
final class ActivateTaxPartyProfile
{
    public function __construct(private readonly TaxMutation $mutation) {}

    public function __invoke(User $actor, int $profileId, string $reason, string $operationKey, int $expectedGeneration): int
    {
        TaxAccess::requireProfile($actor);
        try {
            $outcome = $this->mutation->run($operationKey, $expectedGeneration, ['operation' => 'activate_party_profile', 'actor' => $actor->id, 'profile' => $profileId, 'reason' => $reason], static function () use ($actor, $profileId, $reason): array {
                TaxPartyProfileVersion::query()->findOrFail($profileId);
                $row = TaxPartyProfileActivation::create(['profile_version_id' => $profileId, 'activated_by' => $actor->id, 'reason' => $reason]);

                return ['activation_id' => (int) $row->getKey()];
            });
        } catch (QueryException|ModelNotFoundException) {
            throw TaxRefusal::because('TAX_PROFILE_INVALID', 'Invalid Tax profile definition.');
        }

        return $outcome['activation_id'];
    }
}
