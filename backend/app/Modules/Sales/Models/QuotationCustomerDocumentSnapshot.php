<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * The frozen customer-facing projection of one quotation revision, in the schema version of its document type. History: the database refuses updates and
 * deletes. No application code writes it yet — freezing from a real issued quotation is QL3/DF2.
 *
 * @property int $id
 * @property int $family_id
 * @property int $revision_id
 * @property int $schema_version
 * @property array<string, mixed> $projection
 * @property string $projection_digest
 * @property Carbon $created_at
 */
class QuotationCustomerDocumentSnapshot extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $table = 'quotation_customer_document_snapshots';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['projection' => 'array', 'created_at' => 'datetime'];
    }
}
