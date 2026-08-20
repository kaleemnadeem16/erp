<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Support;

/**
 * Which side of a journal entry is "normal" for an account — see
 * AccountType::normalBalance() and docs/domain/general-ledger.md.
 */
enum NormalBalance: string
{
    case Debit = 'DEBIT';
    case Credit = 'CREDIT';
}
