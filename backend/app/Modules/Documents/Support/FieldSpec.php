<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

final readonly class FieldSpec
{
    public function __construct(public FieldType $type, public bool $required = true) {}
}
