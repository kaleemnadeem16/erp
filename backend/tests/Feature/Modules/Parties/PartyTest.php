<?php

namespace Tests\Feature\Modules\Parties;

use App\Modules\Parties\Models\Party;
use App\Support\Status;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartyTest extends TestCase
{
    use RefreshDatabase;

    public function test_code_is_unique_per_company(): void
    {
        Party::create(['code' => 'ACME', 'name' => 'Acme Industries']);

        $this->expectException(QueryException::class);

        Party::create(['code' => 'ACME', 'name' => 'Duplicate']);
    }

    public function test_status_defaults_to_active_and_casts_to_the_enum(): void
    {
        $party = Party::create(['code' => 'ACME', 'name' => 'Acme Industries']);

        $this->assertSame(Status::Active, $party->fresh()->status);
    }

    public function test_custom_fields_cast_to_an_array_via_has_custom_fields(): void
    {
        $party = Party::create([
            'code' => 'ACME',
            'name' => 'Acme Industries',
            'custom_fields' => ['site_supervisor' => 'J. Doe'],
        ]);

        $this->assertSame(['site_supervisor' => 'J. Doe'], $party->fresh()->custom_fields);
    }

    public function test_it_is_company_scoped(): void
    {
        Party::create(['code' => 'ACME', 'name' => 'Acme Industries']);

        $this->assertSame(1, Party::query()->first()->company_id);
    }

    public function test_contacts_and_sites_relations_resolve(): void
    {
        $party = Party::create(['code' => 'ACME', 'name' => 'Acme Industries']);
        $party->contacts()->create(['name' => 'Jane Doe']);
        $party->sites()->create(['code' => 'HQ', 'name' => 'Head Office']);

        $this->assertSame('Jane Doe', $party->contacts->sole()->name);
        $this->assertSame('HQ', $party->sites->sole()->code);
    }
}
