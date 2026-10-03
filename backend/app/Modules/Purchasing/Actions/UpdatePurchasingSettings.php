<?php

namespace App\Modules\Purchasing\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Purchasing\Models\PurchasingPolicyChange;
use App\Modules\Purchasing\Models\PurchasingSetting;
use App\Support\ActionAuthorization;
use Illuminate\Support\Facades\DB;

final class UpdatePurchasingSettings
{
    /**
     * Over-receipt behaviour decides how much more than ordered may be
     * received and at what tolerance, so it is governed exactly like
     * UpdateInventorySettings' policy groups: elevated permission at the
     * request boundary, mandatory actor and reason here, and an immutable
     * old/new audit row.
     */
    private const POLICY_FIELDS = ['over_receipt_mode', 'over_receipt_tolerance_percent', 'purchase_order_required'];

    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes, User $actor, ?string $reason = null): PurchasingSetting
    {
        if (array_intersect(array_keys($attributes), self::POLICY_FIELDS) !== []) {
            ActionAuthorization::require($actor, 'purchasing.over-receipt-policy.manage', 'change the over-receipt policy');
        }
        $actorId = $actor->id;

        return DB::transaction(function () use ($attributes, $actorId, $reason): PurchasingSetting {
            $settings = PurchasingSetting::query()->lockForUpdate()->firstOrCreate([]);
            // A row created just now carries only the columns the insert
            // named, so its database defaults have to be read back before
            // they can be recorded as the audit row's old values.
            if ($settings->wasRecentlyCreated) {
                $settings->refresh();
            }

            $oldValues = collect(self::POLICY_FIELDS)
                ->filter(fn (string $field): bool => array_key_exists($field, $attributes))
                ->mapWithKeys(fn (string $field): array => [$field => $settings->getRawOriginal($field)])
                ->all();

            $settings->fill($attributes);
            // Eloquent's cast-aware dirty check, so resubmitting an unchanged
            // value in a different lexical form is not recorded as a change.
            $changedFields = array_keys(array_intersect_key($settings->getDirty(), array_flip(self::POLICY_FIELDS)));
            if ($changedFields !== [] && trim((string) $reason) === '') {
                throw new DomainInvariantException('Policy changes require a reason.', 'reason');
            }

            $settings->save();
            $settings->refresh();

            if ($changedFields !== []) {
                PurchasingPolicyChange::query()->create([
                    'policy' => 'OVER_RECEIPT',
                    'old_values' => array_intersect_key($oldValues, array_flip($changedFields)),
                    'new_values' => collect($changedFields)->mapWithKeys(
                        fn (string $field): array => [$field => $settings->getRawOriginal($field)],
                    )->all(),
                    'reason' => trim((string) $reason), 'changed_by' => $actorId, 'changed_at' => now(),
                ]);
            }

            return $settings;
        });
    }
}
