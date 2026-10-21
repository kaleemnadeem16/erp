<?php

declare(strict_types=1);

namespace Tests\Support\Documents;

use App\Modules\Documents\Support\DocumentAccess;
use App\Modules\Documents\Support\DocumentOperationContext;
use App\Modules\Documents\Support\DocumentTypeAdapter;
use App\Modules\Documents\Support\FieldSpec;
use App\Modules\Documents\Support\FieldType;
use App\Modules\Documents\Support\ProjectionSchema;
use App\Modules\Documents\Support\SectionSchema;

/** A quantity-free, blind count sheet: a different line shape from the PO fixture, to show no universal line schema is imposed. */
final class CountSheetAdapter implements DocumentAccess, DocumentTypeAdapter
{
    public function __construct(private readonly bool $throws = false) {}

    public function typeKey(): string
    {
        return 'inventory.count-sheet.blind';
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function audiences(): array
    {
        return ['COUNTER'];
    }

    public function projectionSchema(): ProjectionSchema
    {
        return new ProjectionSchema(
            header: ['sheet_ref' => new FieldSpec(FieldType::String), 'location' => new FieldSpec(FieldType::String)],
            sections: ['items' => new SectionSchema(repeating: true, fields: ['bin' => new FieldSpec(FieldType::String), 'item_code' => new FieldSpec(FieldType::String)])],
        );
    }

    public function access(): DocumentAccess
    {
        return $this;
    }

    public function authorise(DocumentOperationContext $context): bool
    {
        return $this->throws ? throw new \RuntimeException('callback failure must deny') : true;
    }
}
