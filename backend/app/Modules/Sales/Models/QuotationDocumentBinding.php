<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Models\Model;
use App\Modules\Documents\Models\DocumentSubjectBinding;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Sales' side of a Documents identity: the company-scoped foreign keys to the snapshot and to `document_subject_bindings`, which Documents deliberately does
 * not hold. History: the database refuses updates and deletes.
 *
 * @property int $id
 * @property int $snapshot_id
 * @property int $document_binding_id
 * @property string $audience
 * @property Carbon $created_at
 */
class QuotationDocumentBinding extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $table = 'quotation_document_bindings';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @return BelongsTo<QuotationCustomerDocumentSnapshot, $this> */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(QuotationCustomerDocumentSnapshot::class, 'snapshot_id');
    }

    /** @return BelongsTo<DocumentSubjectBinding, $this> */
    public function documentBinding(): BelongsTo
    {
        return $this->belongsTo(DocumentSubjectBinding::class, 'document_binding_id');
    }
}
