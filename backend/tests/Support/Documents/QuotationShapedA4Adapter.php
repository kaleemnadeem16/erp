<?php

declare(strict_types=1);

namespace Tests\Support\Documents;

use App\Modules\Documents\Support\ConstrainsDocumentLayout;
use App\Modules\Documents\Support\DocumentAccess;
use App\Modules\Documents\Support\DocumentLayouts;
use App\Modules\Documents\Support\DocumentOperationContext;
use App\Modules\Documents\Support\DocumentTypeAdapter;
use App\Modules\Documents\Support\FieldSpec;
use App\Modules\Documents\Support\FieldType;
use App\Modules\Documents\Support\LayoutConstraints;
use App\Modules\Documents\Support\PageFormat;
use App\Modules\Documents\Support\ProjectionSchema;
use App\Modules\Documents\Support\SectionSchema;

/**
 * A quotation-shaped, A4-only constraint fixture with NO Sales code: a customer-audience document that must keep its lines and terms and may use only A4
 * (ADR 0133 keeps the quotation A4; Letter is for adapters that opt in). The key is a fixture key, not Sales' real one.
 */
final class QuotationShapedA4Adapter implements ConstrainsDocumentLayout, DocumentAccess, DocumentTypeAdapter
{
    public const KEY = 'fixture.quotation-shaped.customer';

    public function typeKey(): string
    {
        return self::KEY;
    }

    public function schemaVersion(): int
    {
        return 1;
    }

    public function audiences(): array
    {
        return ['CUSTOMER'];
    }

    public function projectionSchema(): ProjectionSchema
    {
        return new ProjectionSchema(
            header: ['reference' => new FieldSpec(FieldType::String), 'valid_through' => new FieldSpec(FieldType::Date)],
            sections: [
                'lines' => new SectionSchema(repeating: true, fields: ['description' => new FieldSpec(FieldType::String), 'amount' => new FieldSpec(FieldType::Decimal)]),
                'terms' => new SectionSchema(fields: ['text' => new FieldSpec(FieldType::String)]),
                'options' => new SectionSchema(repeating: true, fields: ['description' => new FieldSpec(FieldType::String)], required: false),
            ],
        );
    }

    public function access(): DocumentAccess
    {
        return $this;
    }

    public function authorise(DocumentOperationContext $context): bool
    {
        return true;
    }

    public function layoutConstraints(): LayoutConstraints
    {
        return new LayoutConstraints([PageFormat::A4], [DocumentLayouts::STANDARD], ['lines', 'options', 'terms'], ['lines', 'terms']);
    }
}
