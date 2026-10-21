<?php

declare(strict_types=1);

namespace App\Modules\Tax\Actions;

use App\Models\User;
use App\Modules\Tax\Models\TaxComponent;
use App\Modules\Tax\Support\TaxAccess;
use App\Modules\Tax\Support\TaxMutation;

final class CreateTaxComponent
{
    public function __construct(private readonly TaxMutation $mutation) {}

    public function __invoke(User $actor, string $code, string $key, int $expectedGeneration): int
    {
        TaxAccess::requireConfiguration($actor);
        $outcome = $this->mutation->run($key, $expectedGeneration, ['operation' => 'create_component', 'actor' => $actor->id, 'code' => $code], static function () use ($code): array {
            $row = TaxComponent::create(['code' => $code]);

            return ['component_id' => (int) $row->getKey()];
        });

        return $outcome['component_id'];
    }
}
