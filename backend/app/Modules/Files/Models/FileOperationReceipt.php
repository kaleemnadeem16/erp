<?php

declare(strict_types=1);

namespace App\Modules\Files\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;

/** That an operation key was applied with this intent digest, so a retry replays. History. */
class FileOperationReceipt extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'file_operation_receipts';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['recorded_at' => 'datetime'];
    }
}
