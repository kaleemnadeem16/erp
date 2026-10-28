<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

/** The typed content of a template version: a curated layout id, the sections it may show, those it must, and table rules. Nothing else is storable. */
final readonly class TemplateConfiguration
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param  array<mixed>  $permittedSections
     * @param  array<mixed>  $mandatorySections
     */
    public function __construct(
        public string $layoutId,
        public array $permittedSections,
        public array $mandatorySections,
        public bool $repeatTableHeader = true,
        public bool $keepRowsTogether = true,
    ) {
        if (! DocumentLayouts::has($layoutId)) {
            throw new DocumentException("'{$layoutId}' is not a curated layout.", 'layout', 'layout_unknown');
        }
        foreach ([$permittedSections, $mandatorySections] as $list) {
            if (! array_is_list($list) || array_unique($list) !== $list) {
                throw new DocumentException('Sections must be a list of unique names.', 'sections', 'sections_invalid');
            }
            foreach ($list as $name) {
                if (! is_string($name) || preg_match('/^[a-z][a-z0-9_]{0,39}$/', $name) !== 1) {
                    throw new DocumentException('A section name is not valid.', 'sections', 'sections_invalid');
                }
            }
        }
        if (array_diff($mandatorySections, $permittedSections) !== []) {
            throw new DocumentException('A mandatory section must also be permitted.', 'sections', 'sections_invalid');
        }
    }

    /** Checks this template against what the type allows: only permitted sections, every mandatory one, a layout the type accepts. */
    public function assertFits(LayoutConstraints $constraints): void
    {
        if (! in_array($this->layoutId, $constraints->layouts, true)) {
            throw new DocumentException("Layout '{$this->layoutId}' is not allowed for this document type.", 'layout', 'layout_not_allowed');
        }
        if (array_diff($this->permittedSections, $constraints->permittedSections) !== []) {
            throw new DocumentException('The template shows a section this document type does not define.', 'sections', 'section_not_allowed');
        }
        $missing = array_diff($constraints->mandatorySections, $this->mandatorySections);
        if ($missing !== []) {
            throw new DocumentException("The template must keep mandatory section '".reset($missing)."'.", 'sections', 'mandatory_content_missing');
        }
    }
}
