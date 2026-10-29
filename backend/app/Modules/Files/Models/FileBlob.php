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

    /** Where the object lives is private to the storage code: it never appears in an array, JSON or API form of the model. Trusted code still reads the attributes. */
    protected $hidden = ['storage_disk', 'storage_key'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'verified_at' => 'datetime'];
    }
}
