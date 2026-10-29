<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Documents;

use App\Models\User;
use App\Modules\Documents\Actions\BindDocumentManifest;
use App\Modules\Documents\Actions\BindDocumentSubject;
use App\Modules\Documents\Models\DocumentManifest;
use App\Modules\Sales\Models\QuotationIssue;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Kernel\ActiveActor;
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
 * so none can be carried into a snapshot; free text is named `customer_notes` so it cannot be mistaken for an internal note. Access is the owner's: the actor must hold `sales.document.generate` (STAGED, so everyone is refused today) and a
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
                'terms' => new SectionSchema(fields: ['payment_terms' => new FieldSpec(FieldType::String, required: false), 'customer_notes' => new FieldSpec(FieldType::String, required: false)], required: false),
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

        if ($context->operation === BindDocumentManifest::OPERATION_ORIGINAL) {
            return $this->mayFreezeOriginal($context);
        }
        // The subject binding that precedes a freeze carries no purpose; the issuer of that very issued revision may open it (the freeze itself is then judged above).
        if ($context->operation === BindDocumentSubject::OPERATION && $this->isIssuerOfIssuedRevision($context)) {
            return true;
        }

        return SalesAccess::may(User::query()->find($context->actorId), PermissionVocabulary::DOCUMENT_GENERATE);
    }

    /**
     * An ORIGINAL is the customer-facing record of an issue that really happened: the issuer, still an active holder of the issue duties, freezing exactly the issued revision's
     * one issue (identified by its number-bearing record and its financial fingerprint) at its reviewed generation, and nothing else. Documents infers none of this; Sales decides it.
     */
    private function mayFreezeOriginal(DocumentOperationContext $context): bool
    {
        $issue = $context->purpose === DocumentManifest::ORIGINAL ? $this->issuedByActor($context) : null;

        return $issue !== null && $context->stableGenerationIdentity === QuotationCustomerProjection::stableGenerationIdentity($issue->id)
            && $context->sourceGeneration === $issue->reviewed_generation && $context->sourceSnapshotReference === $issue->financial_fingerprint;
    }

    private function isIssuerOfIssuedRevision(DocumentOperationContext $context): bool
    {
        return $this->issuedByActor($context) !== null;
    }

    /** The one issue of the ISSUED revision this binding names, when the actor is an active holder of the issue duties and is the issuer. */
    private function issuedByActor(DocumentOperationContext $context): ?QuotationIssue
    {
        $actor = User::query()->find($context->actorId);
        if ($actor === null || $context->audience !== self::AUDIENCE || ! SalesAccess::mayAll($actor, PermissionVocabulary::QUOTATION_ISSUE, PermissionVocabulary::QUOTATION_VIEW)
            || ActiveActor::current($actor) === null) {
            return null;
        }
        $revision = preg_match('/^quotation-revision:(\d+)$/', $context->ownerBindingIdentity, $m) === 1
            ? QuotationRevision::query()->whereKey((int) $m[1])->where('state', 'ISSUED')->first() : null;
        $issue = $revision === null ? null : QuotationIssue::query()->where('revision_id', $revision->id)->first();

        return $issue !== null && $issue->issued_by === $actor->id ? $issue : null;
    }
}
