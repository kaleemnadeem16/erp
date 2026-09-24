<?php

declare(strict_types=1);

namespace App\Support;

use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * The permission vocabulary shipped by the application.
 *
 * Permission rows are tenant/team data, so registering the vocabulary is an
 * explicit deployment step rather than a migration. Demo data may consume
 * this registry, but production must not depend on the demo seeder.
 */
final class PermissionRegistry
{
    /** @var list<string> */
    private const PERMISSIONS = [
        'inventory.physical_count.create', 'inventory.physical_count.count', 'inventory.physical_count.post',
        'inventory.cutover.create', 'inventory.cutover.stage', 'inventory.cutover.validate', 'inventory.cutover.approve',
        'inventory.cutover.commit', 'inventory.cutover.finalise',
        'inventory.cutover.negative_opening.request', 'inventory.cutover.negative_opening.approve',
        'inventory.negative-stock.request', 'inventory.negative-stock.override',
        'inventory.negative-stock.approve', 'inventory.negative-stock.review',
        'inventory.recalculation.request', 'inventory.recalculation.approve',
        'inventory.recalculation.approve-high-risk', 'inventory.recalculation.execute',
        'inventory.recalculation.review-regeneration',
        'purchasing.over-receipt-policy.manage', 'purchasing.policy-history.view',
        'inventory.location-policy.manage', 'inventory.valuation-policy.manage', 'inventory.policy-history.view',
        'estimating.overhead-policy.manage', 'estimating.policy-history.view',
    ];

    public function __construct(private readonly PermissionRegistrar $registrar) {}

    /** @return list<string> */
    public function all(): array
    {
        return self::PERMISSIONS;
    }

    /**
     * Ensure every application permission exists for the supplied team.
     *
     * @return int Number of vocabulary entries processed.
     */
    public function registerForTeam(int $teamId): int
    {
        $this->registrar->setPermissionsTeamId($teamId);

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission);
        }

        $this->registrar->forgetCachedPermissions();

        return count(self::PERMISSIONS);
    }
}
