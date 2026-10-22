<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Models\Model;
use App\Modules\Documents\Support\TemplateConfiguration;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * One immutable version of a document type's template: a curated layout id plus typed section and table rules. The database refuses every update and delete.
 *
 * @property int $id
 * @property string $type_key
 * @property string $template_key
 * @property int $version
 * @property string $layout_id
 * @property int $schema_version
 * @property list<string> $permitted_sections
 * @property list<string> $mandatory_sections
 * @property bool $repeat_table_header
 * @property bool $keep_rows_together
 * @property int|null $created_by
 * @property Carbon $created_at
 */
class DocumentTemplateVersion extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['permitted_sections' => 'array', 'mandatory_sections' => 'array', 'repeat_table_header' => 'boolean', 'keep_rows_together' => 'boolean', 'created_at' => 'datetime'];

    public function configuration(): TemplateConfiguration
    {
        return new TemplateConfiguration($this->layout_id, $this->permitted_sections, $this->mandatory_sections, $this->repeat_table_header, $this->keep_rows_together);
    }
}
