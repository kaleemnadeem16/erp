<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Services;

use App\Models\User;
use App\Modules\Numbering\Actions\EnsureDefaultSeries;
use App\Modules\Numbering\Models\NumberingSeries;
use App\Modules\Numbering\Models\NumberingSeriesVersion;
use App\Modules\Numbering\Support\NumberableDocumentType;
use App\Modules\Numbering\Support\NumberingAccess;
use App\Modules\Numbering\Support\NumberingException;
use App\Modules\Numbering\Support\NumberingRegistry;
use App\Modules\Numbering\Support\VariableCatalog;

/** What Settings → Document Numbering shows: every registered document type with its series, current format and a live example. */
final class NumberingOverview
{
    public function __construct(
        private readonly NumberingRegistry $registry,
        private readonly EnsureDefaultSeries $ensureDefault,
        private readonly NumberingPreview $preview,
        private readonly NumberingAccess $access,
        private readonly CompanyClock $clock,
        private readonly ConstantsResolver $constants,
    ) {}

    /** @return list<array<string, mixed>> */
    public function types(?User $user): array
    {
        return array_values(array_map(fn (NumberableDocumentType $type): array => $this->type($type, $user), $this->registry->all()));
    }

    /** @return array<string, mixed> */
    public function type(NumberableDocumentType $type, ?User $user): array
    {
        // A type's DEFAULT series is created from its registered defaults the first time it is looked at or used (idempotent).
        ($this->ensureDefault)($type);

        return [
            'key' => $type->key(), 'label' => $type->label(), 'module' => $type->module(), 'official_when' => $type->becomesOfficial(),
            'manage_permission' => $type->managePermission(), 'can_manage' => $this->access->canManage($user, $type->key()),
            'continuity_locked' => $type->continuityLocked(),
            'default_template' => $type->defaultTemplate(), 'default_scope_period' => $type->defaultScope()->period->value,
            'default_continuity' => $type->defaultContinuity()->value,
            'dimensions' => $type->dimensions(), 'routing_attributes' => $type->routingAttributes(),
            'variables' => array_map(fn ($v): array => ['key' => $v->key, 'label' => $v->label, 'frozen' => $v->frozenAtNumbering], $type->variables()),
            'series' => NumberingSeries::query()->where('doc_type', $type->key())->orderByDesc('is_default')->orderBy('code')->get()
                ->map(fn (NumberingSeries $series): array => $this->series($series))->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function series(NumberingSeries $series): array
    {
        $version = NumberingSeriesVersion::query()->where('series_id', $series->id)->where('status', 'ACTIVE')->first();
        $example = null;
        $warnings = [];
        if ($version !== null) {
            try {
                $preview = $this->preview->preview($series->doc_type, $version->template, $version->scope(), $version->continuity, $series->code);
                $example = $preview['example'];
                $warnings = $preview['warnings'];
            } catch (NumberingException) {
                $example = null;
            }
        }

        return [
            'id' => $series->id, 'doc_type' => $series->doc_type, 'code' => $series->code, 'name' => $series->name,
            'is_default' => $series->is_default, 'is_selectable' => $series->is_selectable, 'status' => $series->status,
            'version' => $version === null ? null : [
                'id' => $version->id, 'template' => $version->template, 'scope_period' => $version->scope_period,
                'scope_dimensions' => $version->scope_dimensions ?? [], 'scope_text' => $version->scope()->describe(),
                'continuity' => $version->continuity->value, 'effective_from' => $version->effective_from->toIso8601String(), 'reason' => $version->reason,
            ],
            'example' => $example, 'warnings' => $warnings,
        ];
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return [
            'engine_variables' => array_map(fn (string $key, array $v): array => ['key' => $key, 'label' => $v['label'], 'description' => $v['description'], 'kind' => $v['kind']], array_keys(VariableCatalog::ENGINE), array_values(VariableCatalog::ENGINE)),
            'constants' => array_map(fn (string $code, string $value): array => ['code' => $code, 'value' => $value], array_keys($this->constants->active()), array_values($this->constants->active())),
            'timezone' => $this->clock->timezone(),
            'timezone_is_default' => $this->clock->isDefault(),
        ];
    }
}
