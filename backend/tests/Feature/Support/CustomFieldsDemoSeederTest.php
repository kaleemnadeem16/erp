<?php

namespace Tests\Feature\Support;

use App\Models\CustomFieldDefinition;
use App\Modules\Inventory\Models\Item;
use Database\Seeders\Demo\CustomFieldsDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomFieldsDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_is_visible_and_reruns_preserve_operator_values_and_definition_labels(): void
    {
        $this->artisan('demo:seed')->assertSuccessful();
        $item = Item::query()->where('code', 'demo-pack-thinner')->firstOrFail();
        $this->assertSame('Flammable cabinet', $item->custom_fields['demo_storage_class']);
        $this->assertTrue($item->custom_fields['demo_safety_review']);
        $values = $item->custom_fields;
        $values['demo_manufacturer'] = 'Operator correction';
        $item->update(['custom_fields' => $values]);
        CustomFieldDefinition::query()->where('code', 'demo_manufacturer')->firstOrFail()->update(['label' => 'Producer']);
        $this->seed(CustomFieldsDemoSeeder::class);
        $this->assertSame('Operator correction', $item->fresh()->custom_fields['demo_manufacturer']);
        $this->assertSame('Producer', CustomFieldDefinition::query()->where('code', 'demo_manufacturer')->firstOrFail()->label);
        $this->assertSame(3, CustomFieldDefinition::query()->forEntityType('items')->where('code', 'like', 'demo_%')->count());
    }
}
