<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plain foreign keys prove existence but not tenant ownership. These composite
 * keys make the same-company rule hold even for imports and raw SQL writes.
 */
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: string, 2: string}> */
    private array $references = [
        'categories_parent_company_fk' => ['categories', 'parent_id', 'categories'],
        'chart_of_accounts_parent_company_fk' => ['chart_of_accounts', 'parent_id', 'chart_of_accounts'],
        'items_category_company_fk' => ['items', 'category_id', 'categories'],
        'items_stock_uom_company_fk' => ['items', 'stock_uom_id', 'uoms'],
        'items_default_supplier_company_fk' => ['items', 'default_supplier_id', 'supplier_accounts'],
        'items_income_account_company_fk' => ['items', 'income_account_id', 'chart_of_accounts'],
        'items_expense_account_company_fk' => ['items', 'expense_account_id', 'chart_of_accounts'],
        'items_inventory_account_company_fk' => ['items', 'inventory_account_id', 'chart_of_accounts'],
        'categories_income_account_company_fk' => ['categories', 'income_account_id', 'chart_of_accounts'],
        'categories_expense_account_company_fk' => ['categories', 'expense_account_id', 'chart_of_accounts'],
        'categories_inventory_account_company_fk' => ['categories', 'inventory_account_id', 'chart_of_accounts'],
        'customer_accounts_party_company_fk' => ['customer_accounts', 'party_id', 'parties'],
        'customer_accounts_receivable_account_company_fk' => ['customer_accounts', 'receivable_account_id', 'chart_of_accounts'],
        'supplier_accounts_party_company_fk' => ['supplier_accounts', 'party_id', 'parties'],
        'supplier_accounts_payable_account_company_fk' => ['supplier_accounts', 'payable_account_id', 'chart_of_accounts'],
        'accounting_periods_fiscal_year_company_fk' => ['accounting_periods', 'fiscal_year_id', 'fiscal_years'],
    ];

    /** @var array<string, array{0: string, 1: string, 2: string}> */
    private array $supersededSingleColumnForeignKeys = [
        'categories_parent_id_foreign' => ['categories', 'parent_id', 'categories'],
        'chart_of_accounts_parent_id_foreign' => ['chart_of_accounts', 'parent_id', 'chart_of_accounts'],
        'items_category_id_foreign' => ['items', 'category_id', 'categories'],
        'items_stock_uom_id_foreign' => ['items', 'stock_uom_id', 'uoms'],
        'items_income_account_id_foreign' => ['items', 'income_account_id', 'chart_of_accounts'],
        'items_expense_account_id_foreign' => ['items', 'expense_account_id', 'chart_of_accounts'],
        'items_inventory_account_id_foreign' => ['items', 'inventory_account_id', 'chart_of_accounts'],
        'categories_income_account_id_foreign' => ['categories', 'income_account_id', 'chart_of_accounts'],
        'categories_expense_account_id_foreign' => ['categories', 'expense_account_id', 'chart_of_accounts'],
        'categories_inventory_account_id_foreign' => ['categories', 'inventory_account_id', 'chart_of_accounts'],
        'customer_accounts_party_id_foreign' => ['customer_accounts', 'party_id', 'parties'],
        'customer_accounts_receivable_account_id_foreign' => ['customer_accounts', 'receivable_account_id', 'chart_of_accounts'],
        'supplier_accounts_party_id_foreign' => ['supplier_accounts', 'party_id', 'parties'],
        'supplier_accounts_payable_account_id_foreign' => ['supplier_accounts', 'payable_account_id', 'chart_of_accounts'],
        'accounting_periods_fiscal_year_id_foreign' => ['accounting_periods', 'fiscal_year_id', 'fiscal_years'],
    ];

    public function up(): void
    {
        // Each composite FK below proves both existence and tenant ownership, so retaining
        // the earlier id-only constraint would add duplicate write work without a guarantee.
        foreach ($this->supersededSingleColumnForeignKeys as $constraint => [$table]) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$constraint}");
        }

        $targets = array_unique(array_column($this->references, 2));

        foreach ($targets as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_company_id_id_unique UNIQUE (company_id, id)");
        }

        foreach ($this->references as $constraint => [$table, $column, $target]) {
            DB::statement(
                "ALTER TABLE {$table} ADD CONSTRAINT {$constraint} ".
                "FOREIGN KEY (company_id, {$column}) REFERENCES {$target} (company_id, id)"
            );
        }
    }

    public function down(): void
    {
        foreach (array_reverse(array_keys($this->references)) as $constraint) {
            [$table] = $this->references[$constraint];
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$constraint}");
        }

        $targets = array_reverse(array_unique(array_column($this->references, 2)));

        foreach ($targets as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_company_id_id_unique");
        }

        foreach ($this->supersededSingleColumnForeignKeys as $constraint => [$table, $column, $target]) {
            DB::statement(
                "ALTER TABLE {$table} ADD CONSTRAINT {$constraint} ".
                "FOREIGN KEY ({$column}) REFERENCES {$target} (id)"
            );
        }
    }
};
