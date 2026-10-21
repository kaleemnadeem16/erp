<?php

declare(strict_types=1);

namespace Tests\Support\Documents;

use App\Modules\Documents\Support\DocumentAccess;
use App\Modules\Documents\Support\DocumentOperationContext;
use App\Modules\Documents\Support\DocumentProjection;
use App\Modules\Documents\Support\DocumentTypeAdapter;
use App\Modules\Documents\Support\FieldSpec;
use App\Modules\Documents\Support\FieldType;
use App\Modules\Documents\Support\ProjectionSchema;
use App\Modules\Documents\Support\SectionSchema;

/**
 * A pure, purchase-order-shaped adapter (supplier audience, repeating priced lines, terms). It proves the contract serves a non-Sales document. It
 * deliberately imports nothing from Purchasing and writes nothing: the projection is plain data supplied by the test.
 */
final class PurchaseOrderShapedAdapter implements DocumentAccess, DocumentTypeAdapter
{
    public const KEY = 'purchasing.purchase-order.supplier';

    /** @var list<DocumentOperationContext> */
    public array $asked = [];

    public function __construct(private readonly bool $allow = true) {}

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
        return ['SUPPLIER'];
    }

    public function projectionSchema(): ProjectionSchema
    {
        return new ProjectionSchema(
            header: [
                'po_number' => new FieldSpec(FieldType::String),
                'supplier_name' => new FieldSpec(FieldType::String),
                'currency_code' => new FieldSpec(FieldType::String),
                'order_date' => new FieldSpec(FieldType::Date),
                'expected_date' => new FieldSpec(FieldType::Date, required: false),
                'total' => new FieldSpec(FieldType::Decimal),
            ],
            sections: [
                'lines' => new SectionSchema(repeating: true, fields: [
                    'description' => new FieldSpec(FieldType::String),
                    'quantity' => new FieldSpec(FieldType::Decimal),
                    'uom' => new FieldSpec(FieldType::String),
                    'unit_price' => new FieldSpec(FieldType::Decimal),
                    'amount' => new FieldSpec(FieldType::Decimal),
                ]),
                'terms' => new SectionSchema(fields: ['payment_terms' => new FieldSpec(FieldType::String), 'notes' => new FieldSpec(FieldType::String, required: false)], required: false),
            ],
        );
    }

    public function access(): DocumentAccess
    {
        return $this;
    }

    public function authorise(DocumentOperationContext $context): bool
    {
        $this->asked[] = $context;

        return $this->allow;
    }

    /** @param array<string, mixed> $override */
    public static function projection(array $override = []): DocumentProjection
    {
        return new DocumentProjection(self::KEY, 1, [
            'header' => ['po_number' => 'PO-0001', 'supplier_name' => 'Acme Supplies', 'currency_code' => 'PKR', 'order_date' => '2026-10-21', 'total' => '1250.5000'],
            'sections' => [
                'lines' => [
                    ['description' => 'Pigment', 'quantity' => '10.0000', 'uom' => 'kg', 'unit_price' => '100.0000', 'amount' => '1000.0000'],
                    ['description' => 'Solvent', 'quantity' => '5.0000', 'uom' => 'l', 'unit_price' => '50.1000', 'amount' => '250.5000'],
                ],
                'terms' => ['payment_terms' => 'Net 30'],
            ],
            ...$override,
        ]);
    }
}
