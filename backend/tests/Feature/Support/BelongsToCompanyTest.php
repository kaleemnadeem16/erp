<?php

namespace Tests\Feature\Support;

use App\Support\CompanyContext;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class BelongsToCompanyTest extends TestCase
{
    private const TABLE = 'belongs_to_company_test_widgets';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create(self::TABLE, function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists(self::TABLE);

        parent::tearDown();
    }

    private function widgetModel(): Model
    {
        $model = new class extends Model
        {
            use BelongsToCompany;

            protected $guarded = [];
        };

        $model->setTable(self::TABLE);

        return $model;
    }

    public function test_creating_auto_fills_company_id_from_company_context(): void
    {
        $widget = $this->widgetModel()->newQuery()->create(['name' => 'Widget A']);

        $this->assertSame(1, $widget->company_id);
    }

    public function test_creating_rejects_an_explicit_company_id_outside_the_context(): void
    {
        $this->expectException(RuntimeException::class);

        $this->widgetModel()->newQuery()->create(['name' => 'Widget A', 'company_id' => 7]);
    }

    public function test_company_id_cannot_be_changed_after_creation(): void
    {
        $widget = $this->widgetModel()->newQuery()->create(['name' => 'Widget A']);

        $this->expectException(RuntimeException::class);

        $widget->update(['company_id' => 7]);
    }

    public function test_global_scope_excludes_rows_belonging_to_another_company(): void
    {
        DB::table(self::TABLE)->insert([
            ['company_id' => 1, 'name' => 'Company 1 widget', 'created_at' => now(), 'updated_at' => now()],
            ['company_id' => 2, 'name' => 'Company 2 widget', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $names = $this->widgetModel()->newQuery()->pluck('name')->all();

        $this->assertSame(['Company 1 widget'], $names);
        $this->assertSame(2, DB::table(self::TABLE)->count());
    }

    public function test_global_scope_follows_a_rebound_company_context(): void
    {
        DB::table(self::TABLE)->insert([
            ['company_id' => 1, 'name' => 'Company 1 widget', 'created_at' => now(), 'updated_at' => now()],
            ['company_id' => 2, 'name' => 'Company 2 widget', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->app->instance(CompanyContext::class, new class extends CompanyContext
        {
            public function id(): int
            {
                return 2;
            }
        });

        $names = $this->widgetModel()->newQuery()->pluck('name')->all();

        $this->assertSame(['Company 2 widget'], $names);
    }
}
