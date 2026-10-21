<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

/** Implemented by a module that owns document types; its service provider hands these to the {@see DocumentTypeRegistry}. */
interface DocumentProvider
{
    /** @return list<DocumentTypeAdapter> */
    public function documentTypes(): array;
}
