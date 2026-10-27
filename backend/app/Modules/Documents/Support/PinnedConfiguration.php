<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

/**
 * A resolved template or page policy as a plain, canonical array: where it came from and the configuration itself. A manifest stores this, not only a version
 * id, so a shipped default (which has no row) is pinned too and nothing that changes later can alter what was frozen.
 */
final class PinnedConfiguration
{
    /** @return array<string, mixed> */
    public static function of(ResolvedComponent $component): array
    {
        $configuration = $component->configuration;

        return [
            'source' => $component->source, 'version_id' => $component->versionId, 'key' => $component->key, 'version' => $component->version,
            'configuration' => $configuration instanceof TemplateConfiguration ? [
                'layout_id' => $configuration->layoutId, 'permitted_sections' => $configuration->permittedSections, 'mandatory_sections' => $configuration->mandatorySections,
                'repeat_table_header' => $configuration->repeatTableHeader, 'keep_rows_together' => $configuration->keepRowsTogether,
            ] : [
                'format' => $configuration->format->value, 'orientation' => $configuration->orientation->value, 'width_mm' => $configuration->widthMm(), 'height_mm' => $configuration->heightMm(),
                'margin_top_mm' => $configuration->marginTopMm, 'margin_right_mm' => $configuration->marginRightMm, 'margin_bottom_mm' => $configuration->marginBottomMm,
                'margin_left_mm' => $configuration->marginLeftMm, 'first_header' => $configuration->firstHeader->value, 'continuation_header' => $configuration->continuationHeader->value,
                'first_footer' => $configuration->firstFooter->value, 'continuation_footer' => $configuration->continuationFooter->value, 'compatible_layouts' => $configuration->compatibleLayouts,
            ],
        ];
    }

    /** Stable across key order: the same facts always yield the same digest. */
    public static function digest(mixed $value): string
    {
        return hash('sha256', json_encode(self::canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map(self::canonical(...), $value);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
