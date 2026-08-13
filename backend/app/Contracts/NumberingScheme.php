<?php

namespace App\Contracts;

/**
 * Gapless document numbering — docs/architecture/invariants.md#8-gapless-document-numbering.
 * Core binds App\Support\DefaultNumberingScheme; a plugin rebinds this interface to
 * change the format entirely, per docs/architecture/extensibility.md#container-bound-contracts.
 */
interface NumberingScheme
{
    /**
     * Returns the next number for $docType (e.g. 'invoice', 'quotation') for the
     * current company, resolved via App\Support\CompanyContext.
     */
    public function next(string $docType): string;
}
