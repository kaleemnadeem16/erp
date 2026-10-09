<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests;

use App\Modules\Sales\Support\SalesAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Every Sales request is guarded here (and again in the Action): the capability for the duty, and — because own/assigned/unit visibility is not built —
 * a refusal for anyone an administrator has limited to their units (ADR 0106: unsupported restricted modes fail closed, never a filter).
 */
abstract class SalesRequest extends FormRequest
{
    /** The capability this request needs, or null for a read (any Sales capability that works on these records). */
    abstract protected function capability(): ?string;

    public function authorize(): bool
    {
        $capability = $this->capability();

        return $capability === null ? SalesAccess::mayRead($this->user()) : SalesAccess::may($this->user(), $capability);
    }
}
