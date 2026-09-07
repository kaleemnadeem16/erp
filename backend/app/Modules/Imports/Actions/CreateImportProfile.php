<?php

declare(strict_types=1);

namespace App\Modules\Imports\Actions;

use App\Exceptions\DomainInvariantException;
use App\Modules\Imports\Models\ImportProfile;
use App\Modules\Imports\Support\ImportPurpose;
use App\Modules\Imports\Support\ImportTargetRegistry;
use Illuminate\Support\Facades\DB;

final class CreateImportProfile
{
    public function __construct(private readonly ImportTargetRegistry $targets) {}

    /** @param array<string, string> $mappings source column => target field */
    public function __invoke(string $name, ImportPurpose $purpose, string $entityType, array $mappings): ImportProfile
    {
        $target = $this->targets->get($purpose, $entityType);
        $knownFields = array_keys($target->rules());
        if (trim($name) === '') {
            throw new DomainInvariantException('A profile name is required.', 'name');
        }
        if ($mappings === [] || count(array_unique(array_values($mappings))) !== count($mappings)) {
            throw new DomainInvariantException('A profile requires mappings with unique target fields.', 'mappings');
        }
        foreach ($mappings as $source => $field) {
            if (trim((string) $source) === '' || ! in_array($field, $knownFields, true)) {
                throw new DomainInvariantException('The mapping contains an unknown source or target field.', 'mappings');
            }
        }

        return DB::transaction(function () use ($name, $target, $mappings): ImportProfile {
            $profile = ImportProfile::query()->create(['name' => trim($name), 'entity_type' => $target->entityType()]);
            foreach ($mappings as $source => $field) {
                $profile->mappings()->create(['source_column' => trim((string) $source), 'target_field' => $field]);
            }

            return $profile->load('mappings');
        });
    }
}
