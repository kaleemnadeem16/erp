<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Support;

/**
 * The five fixed double-entry categories — see docs/domain/general-ledger.md
 * "Account type and normal balance". Deliberately no finer subtype
 * (RECEIVABLE, PAYABLE, CASH_BANK, …) yet — that's a future reporting
 * classification whose real vocabulary belongs to Phase 6's financial-statement
 * design, not guessed here. Unlike a composite unique constraint or a scoping
 * mechanism, an unused nullable column is cheap to add later, so there's no
 * structural reason to reserve one speculatively.
 */
enum AccountType: string
{
    case Asset = 'ASSET';
    case Liability = 'LIABILITY';
    case Equity = 'EQUITY';
    case Income = 'INCOME';
    case Expense = 'EXPENSE';

    public function normalBalance(): NormalBalance
    {
        return match ($this) {
            self::Asset, self::Expense => NormalBalance::Debit,
            self::Liability, self::Equity, self::Income => NormalBalance::Credit,
        };
    }
}
