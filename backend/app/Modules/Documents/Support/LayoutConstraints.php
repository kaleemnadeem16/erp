<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

/**
 * What a document type allows its configuration to be: paper formats, curated layouts, the sections a template may show and those it must. These override
 * any default that does not fit (documents.md). Owned by the adapter, enforced by Documents.
 */
final readonly class LayoutConstraints
{
    /**
     * @param  list<PageFormat>  $formats
     * @param  list<string>  $layouts
     * @param  list<string>  $permittedSections
     * @param  list<string>  $mandatorySections
     */
    public function __construct(public array $formats, public array $layouts, public array $permittedSections, public array $mandatorySections)
    {
        if ($formats === [] || $layouts === [] || array_diff($layouts, DocumentLayouts::all()) !== [] || array_diff($mandatorySections, $permittedSections) !== []) {
            throw new DocumentException('Layout constraints need at least one format and curated layout, and mandatory sections must be permitted.', null, 'constraints_invalid');
        }
    }

    public function allowsFormat(PageFormat $format): bool
    {
        return in_array($format, $this->formats, true);
    }

    /** Fail-safe default: A4 only, every curated layout, sections taken from the adapter's own projection schema. Letter needs an explicit opt-in. */
    public static function defaultFor(DocumentTypeAdapter $adapter): self
    {
        $sections = $adapter->projectionSchema()->sections;

        return new self(
            [PageFormat::A4],
            DocumentLayouts::all(),
            array_keys($sections),
            array_keys(array_filter($sections, static fn (SectionSchema $s): bool => $s->required)),
        );
    }
}
