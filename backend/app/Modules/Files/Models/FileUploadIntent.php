<?php

declare(strict_types=1);

namespace App\Modules\Files\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;

/** The durable, idempotent record that an owner asked to upload for a purpose. A mutable projection with a guarded state machine. */
class FileUploadIntent extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'file_upload_intents';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }
}
