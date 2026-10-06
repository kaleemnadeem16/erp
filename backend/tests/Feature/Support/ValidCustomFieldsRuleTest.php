<?php

namespace Tests\Feature\Support;

use App\Models\CustomFieldDefinition;
use App\Rules\Support\ValidCustomFields;
use App\Support\CustomFieldDataType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ValidCustomFieldsRuleTest extends TestCase
{
    use RefreshDatabase;

    private function validate(array $customFields): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make(
            ['custom_fields' => $customFields],
            ['custom_fields' => [new ValidCustomFields('items')]],
        );
    }

    public function test_a_value_matching_its_definitions_data_type_passes(): void
    {
        CustomFieldDefinition::create(['entity_type' => 'items', 'code' => 'weight_kg', 'label' => 'Weight', 'data_type' => CustomFieldDataType::Number]);

        $this->assertFalse($this->validate(['weight_kg' => 12.5])->fails());
    }

    public function test_a_value_not_matching_its_definitions_data_type_fails(): void
    {
        CustomFieldDefinition::create(['entity_type' => 'items', 'code' => 'weight_kg', 'label' => 'Weight', 'data_type' => CustomFieldDataType::Number]);

        $this->assertTrue($this->validate(['weight_kg' => 'twelve'])->fails());
    }

    public function test_a_field_with_no_matching_definition_fails(): void
    {
        $this->assertTrue($this->validate(['not_defined' => 'x'])->fails());
    }

    public function test_a_missing_required_field_fails(): void
    {
        CustomFieldDefinition::create(['entity_type' => 'items', 'code' => 'site_supervisor', 'label' => 'Site Supervisor', 'data_type' => CustomFieldDataType::String, 'is_required' => true]);

        $this->assertTrue($this->validate([])->fails());
    }

    public function test_a_select_value_outside_its_choices_fails(): void
    {
        CustomFieldDefinition::create([
            'entity_type' => 'items',
            'code' => 'colour',
            'label' => 'Colour',
            'data_type' => CustomFieldDataType::Select,
            'options' => ['choices' => ['red', 'green', 'blue']],
        ]);

        $this->assertTrue($this->validate(['colour' => 'purple'])->fails());
        $this->assertFalse($this->validate(['colour' => 'green'])->fails());
    }

    public function test_a_definition_for_a_different_entity_type_does_not_apply(): void
    {
        CustomFieldDefinition::create(['entity_type' => 'quotations', 'code' => 'terms', 'label' => 'Terms', 'data_type' => CustomFieldDataType::String]);

        $this->assertTrue($this->validate(['terms' => 'Net 30'])->fails());
    }

    public function test_required_values_reject_blank_text_but_accept_false_and_zero(): void
    {
        CustomFieldDefinition::create(['entity_type' => 'items', 'code' => 'text', 'label' => 'Text', 'data_type' => CustomFieldDataType::String, 'is_required' => true]);
        CustomFieldDefinition::create(['entity_type' => 'items', 'code' => 'flag', 'label' => 'Flag', 'data_type' => CustomFieldDataType::Bool, 'is_required' => true]);
        CustomFieldDefinition::create(['entity_type' => 'items', 'code' => 'size', 'label' => 'Size', 'data_type' => CustomFieldDataType::Number, 'is_required' => true]);
        $this->assertTrue($this->validate(['text' => ' ', 'flag' => false, 'size' => 0])->fails());
        $this->assertFalse($this->validate(['text' => 'Valid', 'flag' => false, 'size' => 0])->fails());
    }

    public function test_a_date_field_requires_y_m_d_format(): void
    {
        CustomFieldDefinition::create(['entity_type' => 'items', 'code' => 'expiry', 'label' => 'Expiry', 'data_type' => CustomFieldDataType::Date]);

        $this->assertFalse($this->validate(['expiry' => '2026-08-13'])->fails());
        $this->assertTrue($this->validate(['expiry' => '13/08/2026'])->fails());
    }
}
