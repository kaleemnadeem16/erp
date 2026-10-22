<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

/** The typed content of a page-policy version: paper, orientation, margins (mm), header/footer bands for the first and continuation pages, compatible layouts. */
final readonly class PagePolicyConfiguration
{
    /** @param array<mixed> $compatibleLayouts */
    public function __construct(
        public PageFormat $format,
        public PageOrientation $orientation,
        public string $marginTopMm,
        public string $marginRightMm,
        public string $marginBottomMm,
        public string $marginLeftMm,
        public PageBandMode $firstHeader,
        public PageBandMode $continuationHeader,
        public PageBandMode $firstFooter,
        public PageBandMode $continuationFooter,
        public array $compatibleLayouts,
    ) {
        foreach ([$marginTopMm, $marginRightMm, $marginBottomMm, $marginLeftMm] as $margin) {
            if (! FieldType::Decimal->accepts($margin) || bccomp($margin, '5', 4) < 0 || bccomp($margin, '50', 4) > 0) {
                throw new DocumentException('Margins must be decimal millimetres between 5 and 50.', 'margins', 'margins_invalid');
            }
        }
        foreach ([$firstHeader, $continuationHeader] as $mode) {
            if (! in_array($mode, PageBandMode::headerModes(), true)) {
                throw new DocumentException("'{$mode->value}' is not a header mode.", 'header', 'band_invalid');
            }
        }
        foreach ([$firstFooter, $continuationFooter] as $mode) {
            if (! in_array($mode, PageBandMode::footerModes(), true)) {
                throw new DocumentException("'{$mode->value}' is not a footer mode.", 'footer', 'band_invalid');
            }
        }
        if ($compatibleLayouts === [] || ! array_is_list($compatibleLayouts) || array_diff($compatibleLayouts, DocumentLayouts::all()) !== []) {
            throw new DocumentException('A page policy must list at least one curated layout it is compatible with.', 'compatible_layouts', 'layout_unknown');
        }
    }

    public function widthMm(): string
    {
        return $this->orientation === PageOrientation::Portrait ? $this->format->widthMm() : $this->format->heightMm();
    }

    public function heightMm(): string
    {
        return $this->orientation === PageOrientation::Portrait ? $this->format->heightMm() : $this->format->widthMm();
    }

    public function assertFits(LayoutConstraints $constraints): void
    {
        if (! $constraints->allowsFormat($this->format)) {
            throw new DocumentException("Paper format {$this->format->value} is not supported by this document type.", 'format', 'format_not_allowed');
        }
    }
}
