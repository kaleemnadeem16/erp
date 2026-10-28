<?php

declare(strict_types=1);

namespace App\Modules\Files\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;

/** One append-only step of a blob's health or scan history, with the actor or a named service and the database clock. */
class FileBlobEvent extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'file_blob_events';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['evidence' => 'array', 'occurred_at' => 'datetime'];
    }
}
