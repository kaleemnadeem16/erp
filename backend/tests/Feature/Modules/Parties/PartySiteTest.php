<?php

namespace Tests\Feature\Modules\Parties;

use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Models\PartySite;
use App\Support\CompanyContext;
use App\Support\Status;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class PartySiteTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsCompany(int $companyId): void
    {
        $this->app->instance(CompanyContext::class, new class($companyId) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });
    }

    /**
     * parties.company_id has a real FK to companies, unlike the generic
     * BelongsToCompanyThroughParentTest fixtures — a second company must actually
     * exist. Its id can't be assumed to be 2: Postgres sequences aren't
     * transactional, so RefreshDatabase's per-test rollback doesn't reset them,
     * and the id drifts upward as more tests in the suite insert a company.
     */
    private function createSecondCompany(): int
    {
        return DB::table('companies')->insertGetId([
            'name' => 'Other Company',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_code_is_unique_per_party(): void
    {
        $party = Party::create(['code' => 'ACME', 'name' => 'Acme Industries']);
        $party->sites()->create(['code' => 'HQ', 'name' => 'Head Office']);

        $this->expectException(QueryException::class);

        $party->sites()->create(['code' => 'HQ', 'name' => 'Duplicate']);
    }

    public function test_the_same_code_is_allowed_for_a_different_party(): void
    {
        $party1 = Party::create(['code' => 'ACME', 'name' => 'Acme Industries']);
        $party2 = Party::create(['code' => 'GLOBEX', 'name' => 'Globex Corp']);

        $party1->sites()->create(['code' => 'HQ', 'name' => 'Acme HQ']);
        $globexHq = $party2->sites()->create(['code' => 'HQ', 'name' => 'Globex HQ']);

        $this->assertSame('Globex HQ', $globexHq->name);
    }

    public function test_country_code_is_normalised_to_uppercase(): void
    {
        $party = Party::create(['code' => 'ACME', 'name' => 'Acme Industries']);
        $site = $party->sites()->create(['code' => 'HQ', 'name' => 'Head Office', 'country_code' => 'pk']);

        $this->assertSame('PK', $site->country_code);
    }

    public function test_status_defaults_to_active(): void
    {
        $party = Party::create(['code' => 'ACME', 'name' => 'Acme Industries']);
        $site = $party->sites()->create(['code' => 'HQ', 'name' => 'Head Office']);

        $this->assertSame(Status::Active, $site->fresh()->status);
    }

    public function test_it_cannot_be_created_against_another_companys_party(): void
    {
        $this->actingAsCompany($this->createSecondCompany());
        $otherCompanyParty = Party::create(['code' => 'ACME', 'name' => 'Acme Industries']);

        $this->actingAsCompany(1);

        $this->expectException(RuntimeException::class);

        PartySite::create(['party_id' => $otherCompanyParty->id, 'code' => 'HQ', 'name' => 'Head Office']);
    }
}
