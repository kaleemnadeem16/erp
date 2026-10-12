<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Sales\Models\Lead;
use App\Modules\Sales\Models\Opportunity;
use App\Modules\Sales\Models\SalesHistory;
use App\Modules\Sales\Models\SalesStage;
use Database\Seeders\Demo\SalesCrmDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalesCrmDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_demo_shows_every_state_and_the_personas_hold_only_their_duties(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();

        $this->assertSame(['Qualification', 'Proposal', 'Negotiation'], SalesStage::query()->orderBy('sort_order')->pluck('name')->all());
        $this->assertSame(['DISQUALIFIED', 'NEW', 'QUALIFIED'], Lead::query()->orderBy('status')->pluck('status')->map(fn ($s) => $s->value)->unique()->sort()->values()->all());
        $this->assertSame(1, Lead::query()->whereNull('assigned_to')->where('status', 'NEW')->count(), 'one enquiry is new and unassigned');
        $this->assertSame(4, Opportunity::query()->whereNotNull('lead_id')->count(), 'two enquiries produced two deals each');
        $duplicate = Lead::query()->where('enquirer_name', 'Bilal Chaudhry')->sole();
        $this->assertSame('DISQUALIFIED', $duplicate->status->value);
        $this->assertSame(2, Opportunity::query()->where('lead_id', $duplicate->id)->where('outcome', 'OPEN')->count(), 'a duplicate intake is disqualified while its genuine deals stay open');
        $this->assertSame(1, Opportunity::query()->where('outcome', 'LOST')->count());
        $this->assertNotNull(Opportunity::query()->whereNull('expected_amount')->first(), 'an unknown value stays unknown');
        $flagged = Opportunity::query()->where('outcome', 'OPEN')->whereHas('owner', fn ($q) => $q->where('status', 'SUSPENDED'))->get();
        $this->assertCount(1, $flagged, 'a deal whose owner left is flagged, not reassigned');
        $this->assertSame(0, DB::table('customer_accounts')->whereNotIn('code', ['cus-alpha', 'cus-metro'])->count());

        $rep = User::query()->where('email', SalesCrmDemoSeeder::REPRESENTATIVE)->firstOrFail();
        $manager = User::query()->where('email', SalesCrmDemoSeeder::MANAGER)->firstOrFail();
        $viewer = User::query()->where('email', SalesCrmDemoSeeder::VIEWER)->firstOrFail();
        $this->assertTrue($rep->can('sales.opportunity.manage'));
        $this->assertFalse($rep->can('sales.opportunity.assign'));
        $this->assertFalse($rep->can('sales.opportunity.close'));
        $this->assertTrue($manager->can('sales.opportunity.assign') && $manager->can('sales.opportunity.close') && $manager->can('sales.stage.manage'));
        $this->assertTrue($viewer->can('sales.pipeline.view'));
        $this->assertFalse($viewer->can('sales.opportunity.manage'));
        $branch = User::query()->where('email', SalesCrmDemoSeeder::BRANCH)->firstOrFail();
        $this->assertSame(AccessScopeLimit::MY_UNITS, AccessScopeLimit::query()->where('user_id', $branch->id)->sole()->scope);
        $this->assertTrue(SalesHistory::query()->where('event', 'CLOSED')->exists());
    }

    public function test_a_rerun_adds_nothing(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $before = [Lead::query()->count(), Opportunity::query()->count(), SalesHistory::query()->count(), User::query()->count(), SalesStage::query()->count()];
        $this->seed(SalesCrmDemoSeeder::class);
        $this->assertSame($before, [Lead::query()->count(), Opportunity::query()->count(), SalesHistory::query()->count(), User::query()->count(), SalesStage::query()->count()]);
    }

    public function test_module_seeder_refuses_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->expectException(\RuntimeException::class);
        app(SalesCrmDemoSeeder::class)->run();
    }
}
