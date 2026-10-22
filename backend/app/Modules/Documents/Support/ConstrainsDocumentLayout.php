<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

/** Optional, implemented by an adapter that needs more than the A4-only default (for example one that explicitly supports Letter). */
interface ConstrainsDocumentLayout
{
    public function layoutConstraints(): LayoutConstraints;
}
