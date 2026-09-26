<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Estimating;

use App\Models\Uom;
use App\Modules\Estimating\Models\Assembly;
use App\Modules\Estimating\Models\AssemblyGroup;
use App\Modules\Estimating\Support\MarginType;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;

/**
 * Step 1 identity-only demo data (docs/phases/phase-3-estimating-core.md) —
 * no versions/components exist yet, so both assemblies stay DRAFT. Two rows
 * on purpose: one grouped, one not, so the UI and API demonstrate that a
 * group is optional (ADR 0049) rather than every assembly needing one.
 */
final class AssembliesDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    public function run(): void
    {
        $this->ensureDemoEnvironment();

        $sqm = Uom::query()->where('code', 'sqm')->firstOrFail();
        $hour = Uom::query()->where('code', 'hr')->firstOrFail();

        $labour = AssemblyGroup::query()->updateOrCreate(
            ['code' => 'demo-labour'],
            ['name' => 'Labour & Installation'],
        );

        Assembly::query()->updateOrCreate(
            ['code' => 'demo-asm-wall-paint'],
            [
                'name' => 'Wall Painting — 2 coat',
                'description' => 'Prep, prime and two-coat finish for interior walls, priced per square metre.',
                'work_uom_id' => $sqm->id,
                'assembly_group_id' => $labour->id,
                'margin_type' => MarginType::Margin,
                'margin_pct' => '30.0000',
            ],
        );

        Assembly::query()->updateOrCreate(
            ['code' => 'demo-asm-consult'],
            [
                'name' => 'Site Consultation',
                'description' => 'On-site survey and colour consultation, billed hourly — not tied to any group.',
                'work_uom_id' => $hour->id,
                'assembly_group_id' => null,
                'margin_type' => MarginType::Markup,
                'margin_pct' => '25.0000',
            ],
        );
    }
}
