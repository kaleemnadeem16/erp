<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Estimating;

use App\Modules\Estimating\Models\RateDimension;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;

/**
 * One required dimension (Surface Type) and one optional one (Coat Count) —
 * the same shape as docs/domain/application-rates.md's own worked example.
 * See ApplicationRatesDemoSeeder for the rates tagged against these values,
 * deliberately shaped to exercise every tier of the resolution algorithm.
 */
final class RateDimensionsDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    public function run(): void
    {
        $this->ensureDemoEnvironment();

        $surfaceType = RateDimension::query()->updateOrCreate(
            ['code' => 'demo-surface-type'],
            ['name' => 'Surface Type', 'sort_order' => 1, 'is_required' => true],
        );
        $this->values($surfaceType, [
            ['code' => 'demo-smooth', 'name' => 'Smooth plaster', 'sort_order' => 1],
            ['code' => 'demo-rough', 'name' => 'Rough plaster', 'sort_order' => 2],
            ['code' => 'demo-metal', 'name' => 'Metal', 'sort_order' => 3],
        ]);

        $coatCount = RateDimension::query()->updateOrCreate(
            ['code' => 'demo-coat-count'],
            ['name' => 'Coat Count', 'sort_order' => 2, 'is_required' => false],
        );
        $this->values($coatCount, [
            ['code' => 'demo-1-coat', 'name' => 'One coat', 'sort_order' => 1],
            ['code' => 'demo-2-coat', 'name' => 'Two coats', 'sort_order' => 2],
        ]);
    }

    /** @param list<array{code: string, name: string, sort_order: int}> $values */
    private function values(RateDimension $dimension, array $values): void
    {
        foreach ($values as $value) {
            $dimension->values()->updateOrCreate(
                ['code' => $value['code']],
                ['name' => $value['name'], 'sort_order' => $value['sort_order']],
            );
        }
    }
}
