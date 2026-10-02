<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Actions;

use App\Models\User;
use App\Modules\Numbering\Models\NumberingConstant;
use App\Modules\Numbering\Models\NumberingSeriesVersion;
use App\Modules\Numbering\Support\NumberingException;
use App\Modules\Numbering\Support\NumberingRegistry;
use App\Modules\Numbering\Support\TemplateParser;
use App\Modules\Numbering\Support\VariableCatalog;
use Illuminate\Support\Facades\DB;

/** Records a company constant ({REGION}=NORTH), or retires it with a null value. A governed history, future-only (ADR 0079 §13). */
final class SaveConstant
{
    public function __construct(private readonly NumberingRegistry $registry) {}

    public function __invoke(string $code, ?string $value, string $reason, User $actor): NumberingConstant
    {
        if (! $actor->can('numbering.constant.manage')) {
            throw new NumberingException('You are not permitted to change numbering constants.', 'actor');
        }
        if (! preg_match('/^[A-Z][A-Z0-9_]{0,19}$/', $code)) {
            throw new NumberingException('A constant name is upper-case letters, digits and underscores, up to 20 characters, like REGION.', 'code');
        }
        if ($code === 'SEQ' || VariableCatalog::isEngine($code) || $this->isDocumentVariable($code)) {
            throw new NumberingException("{{$code}} is already a built-in variable; choose another name.", 'code');
        }
        if ($value !== null && ! preg_match('/^['.TemplateParser::LITERAL_CHARACTERS.']{1,20}$/', $value)) {
            throw new NumberingException('A constant value is letters, digits and - _ / . only, up to 20 characters.', 'value');
        }
        if (trim($reason) === '') {
            throw new NumberingException('Say why the constant is changing; the reason is kept.', 'reason');
        }
        if ($value === null) {
            $inUse = NumberingSeriesVersion::query()->where('status', 'ACTIVE')->where('template', 'like', '%{'.$code.'}%')->exists();
            if ($inUse) {
                throw new NumberingException("{{$code}} is used by a current number format, so it cannot be retired yet.", 'code');
            }
        }

        return DB::transaction(function () use ($code, $value, $reason, $actor): NumberingConstant {
            $current = NumberingConstant::query()->where('code', $code)->where('status', 'ACTIVE')->lockForUpdate()->first();
            if ($current !== null && $current->value === $value) {
                return $current;
            }
            if ($current === null && $value === null) {
                throw new NumberingException("There is no constant {{$code}} to retire.", 'code');
            }
            $now = now();
            $current?->update(['status' => 'SUPERSEDED', 'effective_to' => $now]);

            return NumberingConstant::query()->create([
                'code' => $code, 'value' => $value, 'status' => 'ACTIVE', 'reason' => trim($reason),
                'effective_from' => $now, 'changed_by' => $actor->id,
            ]);
        });
    }

    private function isDocumentVariable(string $code): bool
    {
        foreach ($this->registry->all() as $type) {
            foreach ($type->variables() as $variable) {
                if ($variable->key === $code) {
                    return true;
                }
            }
        }

        return false;
    }
}
