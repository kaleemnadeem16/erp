<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Support\RejectedExitLines;
use App\Modules\Purchasing\Models\SupplierReturn;
use App\Support\ActionAuthorization;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * Drafts a Supplier Return (ADR 0097). A draft records intent — the delivery lines, quantities, the reasons and, for a grouped item, the declared package portion — and
 * **moves no stock, writes no valuation and no package evidence**; only posting does. The expected credit (amount, currency, reference) is information only: nothing here creates a credit note, changes a supplier balance or posts to the ledger.
 */
final class CreateSupplierReturn
{
    /** @param  array<string,mixed>  $input */
    public function __invoke(array $input, User $actor): SupplierReturn
    {
        ActionAuthorization::require($actor, 'purchasing.supplier-return.create', 'draft a Supplier Return');
        if ((int) $actor->company_id !== app(CompanyContext::class)->id()) {
            throw new DomainInvariantException('The actor must belong to the current company.', 'actor');
        }

        return DB::transaction(function () use ($input, $actor): SupplierReturn {
            $document = new SupplierReturn;
            $this->fill($document, $input, $actor);
            $document->created_by = $actor->id;

            return $this->save($document, $input, $actor);
        });
    }

    /** @param  array<string,mixed>  $input */
    public function fill(SupplierReturn $document, array $input, User $actor): void
    {
        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason === '') {
            throw new DomainInvariantException('A reason is required.', 'reason');
        }
        $document->reason = $reason;
        $amount = $input['expected_credit_amount'] ?? null;
        if ($amount !== null && $amount !== '') {
            if (! is_numeric($amount) || (float) $amount < 0 || empty($input['expected_credit_currency_id'])) {
                throw new DomainInvariantException('An expected credit needs a non-negative amount and its currency.', 'expected_credit_amount');
            }
            $document->expected_credit_amount = (string) $amount;
            $document->expected_credit_currency_id = (int) $input['expected_credit_currency_id'];
        } else {
            $document->expected_credit_amount = null;
            $document->expected_credit_currency_id = null;
        }
        $document->supplier_reference = RejectedExitLines::nullable($input['supplier_reference'] ?? null);
        $document->carrier_note = RejectedExitLines::nullable($input['carrier_note'] ?? null);
        $document->expected_credit_reference = RejectedExitLines::nullable($input['expected_credit_reference'] ?? null);
    }

    /** @param  array<string,mixed>  $input */
    public function save(SupplierReturn $document, array $input, User $actor): SupplierReturn
    {
        $resolved = RejectedExitLines::resolve($input['lines'] ?? [], $actor, ['purchasing.supplier-return.create', 'purchasing.supplier-return.post']);
        if ($resolved['supplier_account_id'] === null) {
            throw new DomainInvariantException('A supplier return goes to one supplier: its lines come from deliveries by different suppliers.', 'lines', 'MIXED_SUPPLIERS');
        }
        $document->supplier_account_id = $resolved['supplier_account_id'];
        $document->warehouse_id = $resolved['warehouse_id'];
        $document->save();
        $document->lines()->delete();
        foreach ($resolved['rows'] as $row) {
            $document->lines()->create($row);
        }

        return $document->refresh()->load('lines');
    }
}
