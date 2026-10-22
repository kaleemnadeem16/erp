<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

/** The shared physical paper profiles (documents.md). Sizes are portrait millimetres. */
enum PageFormat: string
{
    case A4 = 'A4';
    case Letter = 'LETTER';

    public function widthMm(): string
    {
        return match ($this) {
            self::A4 => '210.0',
            self::Letter => '215.9',
        };
    }

    public function heightMm(): string
    {
        return match ($this) {
            self::A4 => '297.0',
            self::Letter => '279.4',
        };
    }
}
