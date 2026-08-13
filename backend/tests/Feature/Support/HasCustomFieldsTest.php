<?php

namespace Tests\Feature\Support;

use App\Models\CustomFieldDefinition;
use App\Support\Concerns\HasCustomFields;
use App\Support\CustomFieldDataType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HasCustomFieldsTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE = 'has_custom_fields_test_widgets';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create(self::TABLE, function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->jsonb('custom_fields')->nullable();
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
        // The table name is a literal (not self::TABLE) because customFieldEntityType()
        // builds a *fresh* instance internally — it needs $table set at class-body level,
        // not via setTable() on one already-constructed instance.
        return new class extends Model
        {
            use HasCustomFields;

            protected $table = 'has_custom_fields_test_widgets';

            protected $guarded = [];
        };
    }

    public function test_custom_fields_column_casts_to_an_array(): void
    {
        $widget = $this->widgetModel()->newQuery()->create([
            'name' => 'Widget A',
            'custom_fields' => ['site_supervisor' => 'Jane'],
        ]);

        $this->assertSame(['site_supervisor' => 'Jane'], $widget->fresh()->custom_fields);
    }

    public function test_entity_type_defaults_to_the_table_name(): void
    {
        $this->assertSame(self::TABLE, $this->widgetModel()::customFieldEntityType());
    }

    public function test_custom_field_definitions_returns_definitions_filed_for_the_table(): void
    {
        CustomFieldDefinition::create([
            'entity_type' => self::TABLE,
            'code' => 'site_supervisor',
            'label' => 'Site Supervisor',
            'data_type' => CustomFieldDataType::String,
        ]);
        CustomFieldDefinition::create([
            'entity_type' => 'somewhere_else',
            'code' => 'irrelevant',
            'label' => 'Irrelevant',
            'data_type' => CustomFieldDataType::String,
        ]);

        $definitions = $this->widgetModel()::customFieldDefinitions();

        $this->assertCount(1, $definitions);
        $this->assertSame('site_supervisor', $definitions->first()->code);
    }

    public function test_where_custom_field_scope_filters_by_json_value(): void
    {
        $this->widgetModel()->newQuery()->create(['name' => 'Match', 'custom_fields' => ['site_supervisor' => 'Jane']]);
        $this->widgetModel()->newQuery()->create(['name' => 'No match', 'custom_fields' => ['site_supervisor' => 'Bob']]);

        $names = $this->widgetModel()->newQuery()->whereCustomField('site_supervisor', 'Jane')->pluck('name');

        $this->assertSame(['Match'], $names->all());
    }
}
