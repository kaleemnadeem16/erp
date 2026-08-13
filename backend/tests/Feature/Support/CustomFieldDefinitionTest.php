<?php

namespace Tests\Feature\Support;

use App\Models\CustomFieldDefinition;
use App\Support\CustomFieldDataType;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomFieldDefinitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_code_is_unique_per_company_and_entity_type(): void
    {
        CustomFieldDefinition::create([
            'entity_type' => 'items',
            'code' => 'site_supervisor',
            'label' => 'Site Supervisor',
            'data_type' => CustomFieldDataType::String,
        ]);

        $this->expectException(QueryException::class);

        CustomFieldDefinition::create([
            'entity_type' => 'items',
            'code' => 'site_supervisor',
            'label' => 'Duplicate',
            'data_type' => CustomFieldDataType::String,
        ]);
    }

    public function test_the_same_code_is_allowed_on_a_different_entity_type(): void
    {
        CustomFieldDefinition::create([
            'entity_type' => 'items',
            'code' => 'reference',
            'label' => 'Reference',
            'data_type' => CustomFieldDataType::String,
        ]);

        $quotationField = CustomFieldDefinition::create([
            'entity_type' => 'quotations',
            'code' => 'reference',
            'label' => 'Reference',
            'data_type' => CustomFieldDataType::String,
        ]);

        $this->assertSame('quotations', $quotationField->entity_type);
    }

    public function test_for_entity_type_scope_filters_and_orders_by_sort_order(): void
    {
        CustomFieldDefinition::create(['entity_type' => 'quotations', 'code' => 'a', 'label' => 'A', 'data_type' => CustomFieldDataType::String, 'sort_order' => 2]);
        CustomFieldDefinition::create(['entity_type' => 'quotations', 'code' => 'b', 'label' => 'B', 'data_type' => CustomFieldDataType::String, 'sort_order' => 1]);
        CustomFieldDefinition::create(['entity_type' => 'items', 'code' => 'c', 'label' => 'C', 'data_type' => CustomFieldDataType::String]);

        $codes = CustomFieldDefinition::query()->forEntityType('quotations')->pluck('code')->all();

        $this->assertSame(['b', 'a'], $codes);
    }

    public function test_data_type_options_and_flags_are_cast(): void
    {
        $definition = CustomFieldDefinition::create([
            'entity_type' => 'items',
            'code' => 'colour',
            'label' => 'Colour',
            'data_type' => CustomFieldDataType::Select,
            'options' => ['choices' => ['red', 'green', 'blue']],
            'is_required' => true,
            'is_searchable' => true,
        ]);

        $this->assertSame(CustomFieldDataType::Select, $definition->data_type);
        $this->assertSame(['red', 'green', 'blue'], $definition->options['choices']);
        $this->assertTrue($definition->is_required);
        $this->assertTrue($definition->is_searchable);
    }
}
