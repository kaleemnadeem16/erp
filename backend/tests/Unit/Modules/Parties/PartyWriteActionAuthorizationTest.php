<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Parties;

use App\Models\User;
use App\Modules\Parties\Actions\ArchiveCustomerAccount;
use App\Modules\Parties\Actions\ArchiveParty;
use App\Modules\Parties\Actions\ArchiveSupplierAccount;
use App\Modules\Parties\Actions\CreateCustomerAccount;
use App\Modules\Parties\Actions\CreateParty;
use App\Modules\Parties\Actions\CreateSupplierAccount;
use App\Modules\Parties\Actions\CreateSupplierSite;
use App\Modules\Parties\Actions\DeleteSupplierSite;
use App\Modules\Parties\Actions\UpdateCustomerAccount;
use App\Modules\Parties\Actions\UpdateParty;
use App\Modules\Parties\Actions\UpdateSupplierAccount;
use App\Modules\Parties\Actions\UpdateSupplierSite;
use App\Modules\Parties\Models\CustomerAccount;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Models\SupplierAccount;
use App\Modules\Parties\Models\SupplierSite;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Mockery;
use Tests\TestCase;

class PartyWriteActionAuthorizationTest extends TestCase
{
    public function test_all_twelve_non_http_write_actions_deny_an_actor_without_capabilities(): void
    {
        $actor = Mockery::mock(User::class)->makePartial();
        $actor->shouldReceive('can')->andReturn(false);
        $party = new Party(['company_id' => 1]);
        $customer = new CustomerAccount(['company_id' => 1]);
        $supplier = new SupplierAccount(['company_id' => 1]);
        $site = new SupplierSite;
        $site->setRelation('supplierAccount', $supplier);

        $operations = [
            fn () => app(CreateParty::class)([], $actor),
            fn () => app(UpdateParty::class)($party, [], $actor),
            fn () => app(ArchiveParty::class)($party, $actor),
            fn () => app(CreateCustomerAccount::class)([], $actor),
            fn () => app(UpdateCustomerAccount::class)($customer, [], $actor),
            fn () => app(ArchiveCustomerAccount::class)($customer, $actor),
            fn () => app(CreateSupplierAccount::class)([], $actor),
            fn () => app(UpdateSupplierAccount::class)($supplier, [], $actor),
            fn () => app(ArchiveSupplierAccount::class)($supplier, $actor),
            fn () => app(CreateSupplierSite::class)($supplier, [], $actor),
            fn () => app(UpdateSupplierSite::class)($site, [], $actor),
            fn () => app(DeleteSupplierSite::class)($site, $actor),
        ];

        foreach ($operations as $operation) {
            try {
                $operation();
                $this->fail('A Parties write Action accepted an unprivileged actor.');
            } catch (AuthorizationException) {
                // The Action fails before it reaches a transaction or mutation.
            }
        }

        $this->assertCount(12, $operations);
    }

    public function test_a_granted_action_cannot_mutate_a_model_from_another_company(): void
    {
        $actor = Mockery::mock(User::class)->makePartial();
        $actor->shouldReceive('can')->with('parties.party.manage')->andReturn(true);
        $otherCompanyParty = new Party(['company_id' => 2]);

        $this->expectException(ModelNotFoundException::class);
        app(UpdateParty::class)($otherCompanyParty, ['name' => 'Changed'], $actor);
    }
}
