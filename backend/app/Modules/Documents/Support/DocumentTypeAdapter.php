<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

/**
 * A module-owned document type, e.g. `sales.quotation.customer` or `purchasing.purchase-order.supplier` (ADR 0137). The owner supplies its typed projection
 * schema, audiences and access; Documents holds the common model. Later slices extend this with layouts, snapshot event and retention callbacks.
 */
interface DocumentTypeAdapter
{
    /** Lowercase dotted `module.document.audience-profile`; unique across the application. */
    public function typeKey(): string;

    public function schemaVersion(): int;

    /** @return list<string> the audience profiles this type can be bound for */
    public function audiences(): array;

    public function projectionSchema(): ProjectionSchema;

    public function access(): DocumentAccess;
}
