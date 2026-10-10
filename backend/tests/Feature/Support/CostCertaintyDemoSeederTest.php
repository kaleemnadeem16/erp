<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\User;
use App\Modules\Estimating\Actions\ExplodeAssemblyVersion;
use App\Modules\Estimating\Models\Assembly;
use Database\Seeders\Demo\Estimating\CostCertaintyDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CostCertaintyDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_examples_distinguish_missing_partial_and_supported_zero_and_are_idempotent(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();
        $actor = User::where('email', config('demo.user.email'))->firstOrFail();
        foreach (['unknown' => 'UNKNOWN', 'partial' => 'PARTIAL', 'zero' => 'COSTED'] as $name => $status) {
            $assembly = Assembly::where('code', 'demo-asm-cost-'.$name)->firstOrFail();
            $result = app(ExplodeAssemblyVersion::class)($assembly->activeVersion, $actor);
            $this->assertSame($status, $result->cost->status->value);
            if ($name === 'zero') {
                $this->assertSame('0.0000', $result->cost->toArray()['total_cost']);
            } else {
                $this->assertNull($result->cost->totalCost);
                $this->assertNull($result->sellPerUnit);
            }
            if ($name === 'partial') {
                $this->assertSame('25.0000', $result->cost->toArray()['known_cost']);
            }
        }
        $before = Assembly::where('code', 'like', 'demo-asm-cost-%')->with('versions.components')->get()->toArray();
        $this->seed(CostCertaintyDemoSeeder::class);
        $this->assertSame($before, Assembly::where('code', 'like', 'demo-asm-cost-%')->with('versions.components')->get()->toArray());
    }
}
