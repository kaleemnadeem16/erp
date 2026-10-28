<?php

declare(strict_types=1);

namespace App\Modules\Files\Support;

/** A module that owns files: its stable key, the purposes it accepts files for, and its own access decision. */
interface FileOwnerAdapter
{
    /** Lowercase dotted `module.subject`; unique across the application. */
    public function ownerTypeKey(): string;

    /** @return list<FilePurpose> */
    public function purposes(): array;

    public function access(): FileAccess;
}
