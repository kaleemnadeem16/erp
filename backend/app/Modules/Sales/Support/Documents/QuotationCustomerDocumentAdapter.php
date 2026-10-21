<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Documents;

use App\Models\User;
use App\Modules\Documents\Support\DocumentAccess;
use App\Modules\Documents\Support\DocumentOperationContext;
use App\Modules\Documents\Support\DocumentTypeAdapter;
use App\Modules\Documents\Support\FieldSpec;
use App\Modules\Documents\Support\FieldType;
use App\Modules\Documents\Support\ProjectionSchema;
use App\Modules\Documents\Support\SectionSchema;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\SalesAccess;

/**
 * The customer-facing quotation document type (ADR 0137). Sales owns the projection allowlist and the access decision; Documents validates shape only and
 * never recomputes money, quantities or consent. The allowlist is customer-safe by construction: it has no cost, margin, control, approval or internal-note field,
 * so none can be carried into a snapshot. Access is the owner's: the actor must hold `sales.document.generate` (STAGED, so everyone is refused today) and a
 * missing actor, unknown user or any failure denies. Money and quantities are decimal strings.
 */
final class QuotationCustomerDocumentAdapter implements DocumentAccess, DocumentTypeAdapter
{
    public const KEY = 'sales.quotation.customer';

    public const AUDIENCE = 'CUSTOMER';

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
        return [self::AUDIENCE];
    }

    public function projectionSchema(): ProjectionSchema
    {
        return new ProjectionSchema(
            header: [
                'quotation_number' => new FieldSpec(FieldType::String),
                'revision_ordinal' => new FieldSpec(FieldType::Integer),
                'issued_on' => new FieldSpec(FieldType::Date),
                'valid_through' => new FieldSpec(FieldType::Date, required: false),
                'currency_code' => new FieldSpec(FieldType::String),
                'recipient_name' => new FieldSpec(FieldType::String),
                'net_total' => new FieldSpec(FieldType::Decimal),
                'tax_total' => new FieldSpec(FieldType::Decimal, required: false),
                'total_including_tax' => new FieldSpec(FieldType::Decimal, required: false),
            ],
            sections: [
                'lines' => new SectionSchema(repeating: true, fields: [
                    'section_title' => new FieldSpec(FieldType::String, required: false),
                    'description' => new FieldSpec(FieldType::String),
                    'membership' => new FieldSpec(FieldType::String),
                    'quantity' => new FieldSpec(FieldType::Decimal),
                    'uom' => new FieldSpec(FieldType::String, required: false),
                    'unit_price' => new FieldSpec(FieldType::Decimal),
                    'amount' => new FieldSpec(FieldType::Decimal),
                ]),
                'terms' => new SectionSchema(fields: ['payment_terms' => new FieldSpec(FieldType::String, required: false), 'notes' => new FieldSpec(FieldType::String, required: false)], required: false),
            ],
        );
    }

    public function access(): DocumentAccess
    {
        return $this;
    }

    public function authorise(DocumentOperationContext $context): bool
    {
        if ($context->actorId === null || $context->typeKey !== self::KEY) {
            return false;
        }

        return SalesAccess::may(User::query()->find($context->actorId), PermissionVocabulary::DOCUMENT_GENERATE);
    }
}
