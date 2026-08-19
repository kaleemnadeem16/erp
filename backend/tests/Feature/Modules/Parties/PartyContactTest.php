<?php

namespace Tests\Feature\Modules\Parties;

use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Models\PartyContact;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class PartyContactTest extends TestCase
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

    public function test_it_belongs_to_a_party(): void
    {
        $party = Party::create(['code' => 'ACME', 'name' => 'Acme Industries']);
        $contact = $party->contacts()->create(['name' => 'Jane Doe', 'is_primary' => true]);

        $this->assertTrue($contact->party->is($party));
        $this->assertTrue($contact->is_primary);
    }

    public function test_it_cannot_be_read_directly_across_companies(): void
    {
        $this->actingAsCompany($this->createSecondCompany());
        $otherCompanyContact = Party::create(['code' => 'ACME', 'name' => 'Acme Industries'])
            ->contacts()->create(['name' => 'Jane Doe']);

        $this->actingAsCompany(1);

        $this->assertNull(PartyContact::find($otherCompanyContact->id));
    }

    public function test_it_cannot_be_created_against_another_companys_party(): void
    {
        $this->actingAsCompany($this->createSecondCompany());
        $otherCompanyParty = Party::create(['code' => 'ACME', 'name' => 'Acme Industries']);

        $this->actingAsCompany(1);

        $this->expectException(RuntimeException::class);

        PartyContact::create(['party_id' => $otherCompanyParty->id, 'name' => 'Jane Doe']);
    }
}
