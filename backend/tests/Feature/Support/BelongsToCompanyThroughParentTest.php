<?php

namespace Tests\Feature\Support;

use App\Support\CompanyContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Feature\Support\Fixtures\ThroughParentTestChild;
use Tests\Feature\Support\Fixtures\ThroughParentTestGrandparent;
use Tests\Feature\Support\Fixtures\ThroughParentTestParent;
use Tests\TestCase;

class BelongsToCompanyThroughParentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('through_parent_test_grandparents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('through_parent_test_parents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grandparent_id')->constrained('through_parent_test_grandparents');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('through_parent_test_children', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->constrained('through_parent_test_parents');
            $table->string('name');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('through_parent_test_children');
        Schema::dropIfExists('through_parent_test_parents');
        Schema::dropIfExists('through_parent_test_grandparents');

        parent::tearDown();
    }

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
     * @return array{grandparent: ThroughParentTestGrandparent, parent: ThroughParentTestParent, child: ThroughParentTestChild}
     */
    private function createChainForCurrentCompany(string $label): array
    {
        $grandparent = ThroughParentTestGrandparent::create(['name' => "{$label} grandparent"]);
        $parent = ThroughParentTestParent::create([
            'grandparent_id' => $grandparent->id,
            'name' => "{$label} parent",
        ]);
        $child = ThroughParentTestChild::create([
            'parent_id' => $parent->id,
            'name' => "{$label} child",
        ]);

        return ['grandparent' => $grandparent, 'parent' => $parent, 'child' => $child];
    }

    public function test_creating_succeeds_when_the_parent_belongs_to_the_current_company(): void
    {
        $this->actingAsCompany(1);

        $chain = $this->createChainForCurrentCompany('Company 1');

        $this->assertNotNull(ThroughParentTestChild::find($chain['child']->id));
    }

    public function test_a_child_from_another_company_cannot_be_read_directly(): void
    {
        $this->actingAsCompany(2);
        $otherCompanyChild = $this->createChainForCurrentCompany('Company 2')['child'];

        $this->actingAsCompany(1);

        $this->assertNull(ThroughParentTestChild::find($otherCompanyChild->id));
        $this->assertSame([], ThroughParentTestChild::pluck('name')->all());
    }

    public function test_creating_a_child_against_another_companys_parent_throws(): void
    {
        $this->actingAsCompany(2);
        $otherCompanyParent = $this->createChainForCurrentCompany('Company 2')['parent'];

        $this->actingAsCompany(1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not resolve to a');

        ThroughParentTestChild::create([
            'parent_id' => $otherCompanyParent->id,
            'name' => 'Should not be created',
        ]);
    }

    public function test_updating_a_child_to_another_companys_parent_throws(): void
    {
        $this->actingAsCompany(1);
        $ownChild = $this->createChainForCurrentCompany('Company 1')['child'];

        $this->actingAsCompany(2);
        $otherCompanyParent = $this->createChainForCurrentCompany('Company 2')['parent'];

        $this->actingAsCompany(1);
        $this->expectException(RuntimeException::class);

        $ownChild->update(['parent_id' => $otherCompanyParent->id]);
    }

    public function test_scoping_composes_through_multiple_hops(): void
    {
        $this->actingAsCompany(2);
        $this->createChainForCurrentCompany('Company 2');

        $this->actingAsCompany(1);
        $ownChild = $this->createChainForCurrentCompany('Company 1')['child'];

        // Two hops removed from company_id (child -> parent -> grandparent), yet only
        // company 1's row is visible — proving the scope isn't a single-level check.
        $this->assertSame(['Company 1 child'], ThroughParentTestChild::pluck('name')->all());
        $this->assertSame($ownChild->id, ThroughParentTestChild::sole()->id);
    }
}
