<?php

namespace Tests\Feature\Modules\Estimating;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Estimating\Actions\ActivateAssemblyVersion;
use App\Modules\Estimating\Actions\ReadAssemblyCostEvidence;
use App\Modules\Estimating\Models\Assembly;
use App\Modules\Estimating\Models\AssemblyVersion;
use App\Modules\Estimating\Support\AssemblyCostEvidence;
use App\Modules\Estimating\Support\CostEvidenceCertainty;
use App\Modules\Estimating\Support\CostEvidenceFailure;
use App\Modules\Estimating\Support\CostEvidenceWorkflow;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\AvcoPoolStatus;
use App\Modules\Inventory\Support\ItemType;
use App\Support\CompanyContext;
use App\Support\UomDimension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

/**
 * #29a — the Estimating cost-evidence seam: ONE active Assembly version and work quantity, the figures only for the authorised workflow, nothing confidential in any
 * result, error, log or serialisation, company-isolated, read-only, and no permission involved.
 */
class AssemblyCostEvidenceSeamTest extends TestCase
{
    use RefreshDatabase;

    private const WORKFLOW = CostEvidenceWorkflow::QuotationControlEvaluation;

    private Uom $kg;

    private Uom $sqm;

    private Item $pigment;

    private Item $uncosted;

    private User $caller;

    protected function setUp(): void
    {
        parent::setUp();
        $pkr = Currency::firstOrCreate(['code' => 'PKR'], ['name' => 'Rupee', 'symbol' => 'Rs', 'decimal_places' => 2]);
        Company::findOrFail(1)->update(['base_currency_id' => $pkr->id]);
        $this->kg = Uom::create(['code' => 'kg', 'name' => 'Kilogram', 'dimension' => UomDimension::Mass, 'factor_to_base' => '1', 'is_base' => true]);
        $this->sqm = Uom::create(['code' => 'sqm', 'name' => 'Square metre', 'dimension' => UomDimension::Area, 'factor_to_base' => '1', 'is_base' => true]);
        $category = Category::create(['code' => 'RAW', 'name' => 'Raw', 'default_valuation_method' => 'AVCO']);
        $this->pigment = Item::create(['code' => 'cost-pigment', 'name' => 'Pigment', 'type' => ItemType::Raw, 'stock_uom_id' => $this->kg->id, 'category_id' => $category->id]);
        AvcoPool::create([
            'item_id' => $this->pigment->id, 'scope' => AvcoPoolScope::CompanyItem, 'qty_on_hand' => '100', 'inventory_value' => '1000', 'avg_unit_cost' => '10.0000',
            'base_avg_unit_cost' => '10.0000', 'last_valid_avg_unit_cost' => '10.0000', 'status' => AvcoPoolStatus::Active,
        ]);
        $this->uncosted = Item::create(['code' => 'cost-unknown', 'name' => 'No cost yet', 'type' => ItemType::Raw, 'stock_uom_id' => $this->kg->id, 'category_id' => $category->id]);
        // The caller holds NO estimating permission at all: the seam's authority is the workflow, not a grant.
        $this->caller = User::factory()->create();
    }

    private function version(array $components, bool $activate = true): AssemblyVersion
    {
        $assembly = Assembly::create(['code' => 'ASM-'.str()->random(6), 'name' => 'Seam Assembly', 'work_uom_id' => $this->sqm->id, 'margin_type' => 'MARKUP', 'margin_pct' => '20']);
        $version = $assembly->versions()->create(['version_no' => 1])->refresh();
        foreach ($components as $sequence => $component) {
            $version->components()->create([...$component, 'sequence' => $sequence]);
        }
        if ($activate) {
            (new ActivateAssemblyVersion)($version, PermissionedActor::grant(User::factory()->create(), ['estimating.assembly.manage']));
        }

        return $version->refresh();
    }

    /** 0.5 kg of pigment at 10.0000 plus a 100.0000 subcontract per square metre: 105.0000 per work unit. */
    private function costedVersion(): AssemblyVersion
    {
        return $this->version([
            ['kind' => 'MATERIAL', 'item_id' => $this->pigment->id, 'qty_per_work_unit' => '0.500000', 'uom_id' => $this->kg->id],
            ['kind' => 'SUBCONTRACT', 'qty_per_work_unit' => '1', 'uom_id' => $this->sqm->id, 'rate_override' => '100.0000'],
        ]);
    }

    private function read(int $versionId, string $quantity, ?User $actor = null): AssemblyCostEvidence
    {
        return app(ReadAssemblyCostEvidence::class)($versionId, $quantity, self::WORKFLOW, $actor ?? $this->caller);
    }

    public function test_complete_evidence_carries_the_exact_cost_for_the_quantity_only_to_the_workflow(): void
    {
        $version = $this->costedVersion();

        $evidence = $this->read($version->id, '3');

        $this->assertSame(CostEvidenceCertainty::Complete, $evidence->certainty);
        $this->assertNull($evidence->reasonCode);
        $this->assertSame($version->id, $evidence->assemblyVersionId);
        $amounts = $evidence->amounts(self::WORKFLOW);
        $this->assertSame('315.0000', (string) $amounts->total());
        $this->assertSame('315.0000', (string) $amounts->known());
        $this->assertNotNull($amounts->currencyId());
        $this->assertSame('52.5000', (string) $this->read($version->id, '0.5')->amounts(self::WORKFLOW)->total(), 'The quantity scales the cost exactly.');
    }

    public function test_it_needs_no_estimating_permission_and_grants_none(): void
    {
        $version = $this->costedVersion();
        $before = Permission::query()->count();

        $this->assertFalse($this->caller->can('estimating.internal_cost.view'));
        $this->assertSame(CostEvidenceCertainty::Complete, $this->read($version->id, '1')->certainty);
        $this->assertFalse($this->caller->fresh()->can('estimating.internal_cost.view'));
        $this->assertSame($before, Permission::query()->count(), 'No permission row was created by reading evidence.');
        $this->assertSame([], $this->caller->fresh()->getAllPermissions()->pluck('name')->all());
    }

    public function test_partial_cost_is_partial_never_complete_and_unknown_cost_has_no_figures(): void
    {
        $partial = $this->version([
            ['kind' => 'MATERIAL', 'item_id' => $this->uncosted->id, 'qty_per_work_unit' => '1', 'uom_id' => $this->kg->id],
            ['kind' => 'SUBCONTRACT', 'qty_per_work_unit' => '1', 'uom_id' => $this->sqm->id, 'rate_override' => '100.0000'],
        ]);
        $unknown = $this->version([['kind' => 'MATERIAL', 'item_id' => $this->uncosted->id, 'qty_per_work_unit' => '1', 'uom_id' => $this->kg->id]]);

        $p = $this->read($partial->id, '2');
        $u = $this->read($unknown->id, '2');

        $this->assertSame(CostEvidenceCertainty::Partial, $p->certainty);
        $this->assertSame(AssemblyCostEvidence::COST_PARTIAL, $p->reasonCode);
        $this->assertNull($p->amounts(self::WORKFLOW)->total(), 'A partial cost has no total.');
        $this->assertSame('200.0000', (string) $p->amounts(self::WORKFLOW)->known(), 'Only a lower bound is known, and it is not the cost.');
        $this->assertSame(CostEvidenceCertainty::Unknown, $u->certainty);
        $this->assertSame(AssemblyCostEvidence::COST_UNKNOWN, $u->reasonCode);
        $this->assertNull($u->amounts(self::WORKFLOW));
    }

    public function test_what_cannot_be_established_is_unknown_with_a_fixed_reason_never_an_error_or_zero(): void
    {
        $draft = $this->version([['kind' => 'SUBCONTRACT', 'qty_per_work_unit' => '1', 'uom_id' => $this->sqm->id, 'rate_override' => '100.0000']], activate: false);
        $active = $this->costedVersion();

        $cases = [
            'unknown version' => [999999, '1', AssemblyCostEvidence::ASSEMBLY_VERSION_NOT_FOUND],
            'draft version' => [$draft->id, '1', AssemblyCostEvidence::ASSEMBLY_VERSION_NOT_ACTIVE],
            'zero quantity' => [$active->id, '0', AssemblyCostEvidence::WORK_QUANTITY_INVALID],
            'negative quantity' => [$active->id, '-1', AssemblyCostEvidence::WORK_QUANTITY_INVALID],
            'seven places' => [$active->id, '1.0000001', AssemblyCostEvidence::WORK_QUANTITY_INVALID],
            'not a number' => [$active->id, 'abc', AssemblyCostEvidence::WORK_QUANTITY_INVALID],
            'exponent' => [$active->id, '1e3', AssemblyCostEvidence::WORK_QUANTITY_INVALID],
        ];
        foreach ($cases as $name => [$id, $quantity, $reason]) {
            $evidence = $this->read($id, $quantity);
            $this->assertSame(CostEvidenceCertainty::Unknown, $evidence->certainty, $name);
            $this->assertSame($reason, $evidence->reasonCode, $name);
            $this->assertNull($evidence->amounts(self::WORKFLOW), $name);
        }
    }

    public function test_another_companys_version_is_indistinguishable_from_a_missing_one(): void
    {
        $version = $this->costedVersion();
        $company = DB::table('companies')->insertGetId(['name' => 'Other Company', 'created_at' => now(), 'updated_at' => now()]);
        $other = DB::table('users')->insertGetId(['company_id' => $company, 'name' => 'O', 'email' => 'o@other.test', 'password' => 'x', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        $this->app->instance(CompanyContext::class, new class($company) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });
        $outsider = User::query()->findOrFail($other);

        $evidence = $this->read($version->id, '1', $outsider);

        $this->assertSame(AssemblyCostEvidence::ASSEMBLY_VERSION_NOT_FOUND, $evidence->reasonCode);
        $this->assertSame($this->read(999999, '1', $outsider)->reasonCode, $evidence->reasonCode);
        $this->assertNull($evidence->amounts(self::WORKFLOW));
    }

    public function test_an_actor_from_another_company_is_refused(): void
    {
        $version = $this->costedVersion();
        $company = DB::table('companies')->insertGetId(['name' => 'Other Company', 'created_at' => now(), 'updated_at' => now()]);
        $other = User::factory()->create();
        DB::table('users')->where('id', $other->id)->update(['company_id' => $company]);

        try {
            $this->read($version->id, '1', $other->fresh());
            $this->fail('An actor outside the active company must be refused.');
        } catch (DomainInvariantException $refusal) {
            $this->assertSame('ACTOR_OUTSIDE_COMPANY', $refusal->reasonCode);
        }
    }

    public function test_no_confidential_figure_appears_in_any_representation(): void
    {
        $version = $this->costedVersion();
        $evidence = $this->read($version->id, '3');
        $amounts = $evidence->amounts(self::WORKFLOW);
        $secrets = ['315', '105', '10.5000', '100.0000'];

        $representations = [
            'json evidence' => json_encode($evidence),
            'json amounts' => json_encode($amounts),
            'var_export evidence' => var_export($evidence, true),
            'var_export amounts' => var_export($amounts, true),
            'print_r evidence' => print_r($evidence, true),
            'print_r amounts' => print_r($amounts, true),
            'var_dump evidence' => (function () use ($evidence): string {
                ob_start();
                var_dump($evidence);

                return (string) ob_get_clean();
            })(),
            'var_dump amounts' => (function () use ($amounts): string {
                ob_start();
                var_dump($amounts);

                return (string) ob_get_clean();
            })(),
        ];
        foreach ($representations as $name => $text) {
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, (string) $text, "{$name} leaked {$secret}");
            }
        }
        foreach ([$evidence, $amounts] as $object) {
            try {
                serialize($object);
                $this->fail('Cost evidence must not serialise.');
            } catch (\LogicException $e) {
                $this->assertStringNotContainsString('315', $e->getMessage());
            }
        }
        $this->assertSame(['assembly_version_id' => $version->id, 'certainty' => 'COMPLETE', 'reason_code' => null], json_decode((string) json_encode($evidence), true));
    }

    public function test_an_unexpected_failure_is_a_fixed_message_with_nothing_chained_and_nothing_logged(): void
    {
        $version = $this->costedVersion();
        Log::spy();
        DB::listen(function ($query): void {
            if (str_contains($query->sql, 'avco_pools')) {
                throw new \RuntimeException('secret cost 315.0000 for item cost-pigment');
            }
        });

        try {
            $this->read($version->id, '3');
            $this->fail('An unexpected failure must surface as CostEvidenceFailure.');
        } catch (CostEvidenceFailure $failure) {
            $this->assertSame('Cost evidence could not be read.', $failure->getMessage());
            $this->assertNull($failure->getPrevious());
            $this->assertStringNotContainsString('315', $failure->getTraceAsString().$failure->getMessage());
        }
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('critical');
    }

    public function test_reading_evidence_writes_nothing(): void
    {
        $version = $this->costedVersion();
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|truncate|alter|drop|create)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        $this->read($version->id, '3')->amounts(self::WORKFLOW)->total();
        $this->read(999999, '1');

        $this->assertSame([], $writes, 'The seam is read-only.');
    }
}
