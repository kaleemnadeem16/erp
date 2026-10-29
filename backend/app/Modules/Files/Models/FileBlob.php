<?php

declare(strict_types=1);

namespace App\Modules\Files\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;

/** One stored object's immutable identity (company, disk, opaque key, SHA-256, size, sniffed type); only its health and scan state move. Bytes live on the private disk, never here. */
class FileBlob extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'file_blobs';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'verified_at' => 'datetime'];
    }
}
