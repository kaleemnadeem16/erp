<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\CustomFieldDefinition;
use App\Models\User;
use App\Modules\Administration\Actions\CreateCustomField;
use App\Modules\Inventory\Actions\UpdateItem;
use App\Modules\Inventory\Models\Item;
use App\Support\CompanyContext;
use App\Support\PermissionRegistry;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;

/** Optional descriptive fields; reruns preserve operator changes to definitions and values. */
final class CustomFieldsDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    public function run(): void
    {
        $this->ensureDemoEnvironment();
        app(PermissionRegistry::class)->registerForTeam(app(CompanyContext::class)->id());
        $actor = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        $fields = [
            ['code' => 'demo_manufacturer', 'label' => 'Manufacturer', 'data_type' => 'string'],
            ['code' => 'demo_safety_review', 'label' => 'Safety review completed', 'data_type' => 'bool'],
            ['code' => 'demo_storage_class', 'label' => 'Storage class', 'data_type' => 'select', 'options' => ['choices' => ['General', 'Flammable cabinet']]],
        ];
        foreach ($fields as $position => $field) {
            if (! CustomFieldDefinition::query()->forEntityType('items')->where('code', $field['code'])->exists()) {
                app(CreateCustomField::class)(array_merge($field, ['entity_type' => 'items', 'is_required' => false, 'sort_order' => $position]), $actor);
            }
        }
        $item = Item::query()->where('code', 'demo-pack-thinner')->first();
        if ($item !== null) {
            $values = ($item->custom_fields ?? []) + ['demo_manufacturer' => 'Demo industrial supplies', 'demo_safety_review' => true, 'demo_storage_class' => 'Flammable cabinet'];
            app(UpdateItem::class)($item, ['custom_fields' => $values], $actor);
        }
    }
}
