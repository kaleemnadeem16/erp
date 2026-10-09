<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Models\User;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A human note on a lead or an opportunity, with its author and time. Append-only.
 *
 * @property int $id
 * @property int|null $lead_id
 * @property int|null $opportunity_id
 * @property int $author_id
 * @property string $body
 * @property Carbon $created_at
 */
class SalesNote extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $table = 'sales_notes';

    protected $guarded = ['id'];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
