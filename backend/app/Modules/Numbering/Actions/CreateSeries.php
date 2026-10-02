<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Actions;

use App\Models\User;
use App\Modules\Numbering\Models\NumberingSeries;
use App\Modules\Numbering\Support\Continuity;
use App\Modules\Numbering\Support\CounterScope;
use App\Modules\Numbering\Support\NumberingException;
use App\Modules\Numbering\Support\NumberingRegistry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Adds a non-default series to a document type (Import PO, Local PO…) with its first version. */
final class CreateSeries
{
    public function __construct(private readonly NumberingRegistry $registry, private readonly EnsureDefaultSeries $ensureDefault, private readonly SaveSeriesVersion $saveVersion) {}

    public function __invoke(string $docType, string $code, string $name, string $template, CounterScope $scope, Continuity $continuity, bool $selectable, string $reason, User $actor): NumberingSeries
    {
        $type = $this->registry->get($docType);
        if (! $actor->can($type->managePermission())) {
            throw new NumberingException("You are not permitted to change {$type->label()} numbering.", 'actor');
        }
        if (! preg_match('/^[A-Z][A-Z0-9_]{0,29}$/', $code)) {
            throw new NumberingException('A series code is upper-case letters, digits and underscores, like IMPORT.', 'code');
        }
        if (trim($name) === '') {
            throw new NumberingException('A series needs a name.', 'name');
        }
        ($this->ensureDefault)($type); // the default always exists before a second series does

        return DB::transaction(function () use ($docType, $code, $name, $template, $scope, $continuity, $selectable, $reason, $actor): NumberingSeries {
            try {
                $series = NumberingSeries::query()->create(['doc_type' => $docType, 'code' => $code, 'name' => trim($name), 'is_default' => false, 'is_selectable' => $selectable, 'status' => 'ACTIVE']);
            } catch (UniqueConstraintViolationException) {
                throw new NumberingException("A series '{$code}' already exists for this document type.", 'code');
            }
            ($this->saveVersion)($series, $template, $scope, $continuity, $reason, $actor);

            return $series;
        });
    }
}
