<?php

declare(strict_types=1);

namespace App\Modules\Tax\Actions;

use App\Models\User;
use App\Modules\Parties\Models\Party;
use App\Modules\Tax\Models\TaxPartyComponentEvidence;
use App\Modules\Tax\Models\TaxPartyProfileVersion;
use App\Modules\Tax\Support\EffectivePeriod;
use App\Modules\Tax\Support\TaxAccess;
use App\Modules\Tax\Support\TaxMutation;
use App\Modules\Tax\Support\TaxRefusal;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;

/** Internal, disabled foundation. Each successor supplies a complete new set of declared component facts. */
final class CreateTaxPartyProfileSuccessor
{
    public function __construct(private readonly TaxMutation $mutation) {}

    /** @param list<array<string, int|string|null>> $components */
    public function __invoke(User $actor, int $partyId, string $jurisdiction, ?int $predecessorId, EffectivePeriod $period, string $jurisdictionEvidence, ?string $registrationDetails, array $components, string $reason, string $operationKey, int $expectedGeneration): int
    {
        TaxAccess::requireProfile($actor);
        foreach ($components as &$component) {
            if (array_diff(array_keys($component), ['component_id', 'disposition', 'grounds', 'evidence_reference', 'issuer', 'issued_on', 'expires_on']) !== []) {
                throw TaxRefusal::because('TAX_PROFILE_INVALID', 'Invalid Tax profile definition.');
            }
            $component = array_replace(['grounds' => null, 'evidence_reference' => null, 'issuer' => null, 'issued_on' => null, 'expires_on' => null], $component);
            ksort($component);
        }
        unset($component);
        usort($components, static fn (array $a, array $b): int => ($a['component_id'] ?? 0) <=> ($b['component_id'] ?? 0));
        $intent = ['operation' => 'create_party_profile', 'actor' => $actor->id, 'party' => $partyId, 'jurisdiction' => $jurisdiction, 'predecessor' => $predecessorId, 'from' => $period->from, 'to' => $period->endExclusive, 'jurisdiction_evidence' => $jurisdictionEvidence, 'registration_details' => $registrationDetails, 'components' => $components, 'reason' => $reason];
        try {
            $outcome = $this->mutation->run($operationKey, $expectedGeneration, $intent, static function () use ($actor, $partyId, $jurisdiction, $predecessorId, $period, $jurisdictionEvidence, $registrationDetails, $components, $reason): array {
                Party::query()->findOrFail($partyId);
                $predecessor = $predecessorId === null ? null : TaxPartyProfileVersion::query()->findOrFail($predecessorId);
                $profile = TaxPartyProfileVersion::create([
                    'party_id' => $partyId, 'jurisdiction' => $jurisdiction, 'jurisdiction_evidence' => $jurisdictionEvidence,
                    'registration_details' => $registrationDetails, 'predecessor_id' => $predecessorId,
                    'version_no' => $predecessor === null ? 1 : (int) $predecessor->getAttribute('version_no') + 1,
                    'effective_from' => $period->from, 'effective_to' => $period->endExclusive,
                    'created_by' => $actor->id, 'reason' => $reason,
                ]);
                foreach ($components as $component) {
                    TaxPartyComponentEvidence::create($component + ['profile_version_id' => $profile->getKey(), 'created_by' => $actor->id, 'reason' => $reason]);
                }

                return ['profile_version_id' => (int) $profile->getKey()];
            });
        } catch (QueryException|ModelNotFoundException) {
            // Never chain SQL/bindings, offending evidence or existence details into public errors/logs.
            throw TaxRefusal::because('TAX_PROFILE_INVALID', 'Invalid Tax profile definition.');
        }

        return $outcome['profile_version_id'];
    }
}
