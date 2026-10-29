<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support\Render;

/**
 * Port: turns the frozen inputs of one manifest into the bytes of a document. A renderer is identified by a contract id and a version, both pinned in every manifest
 * made for it, together with the fonts it draws with; it reads nothing but its input (no current master, preference, clock or network) and executes no markup, script or
 * external resource. The same input and renderer version always give the same bytes.
 */
interface DocumentRenderer
{
    public function contract(): string;

    public function version(): string;

    /** @return list<array{family: string, style: string, embedded: bool, encoding: string}> */
    public function fonts(): array;

    public function render(RenderInput $input): RenderedDocument;
}
