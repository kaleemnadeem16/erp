<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

enum NegativeStockMovementClass: string
{
    case SalesDespatch = 'SALES_DESPATCH';
    case MaterialConsumption = 'MATERIAL_CONSUMPTION';
    case SupplierReturn = 'SUPPLIER_RETURN';
    case ScrapWriteoff = 'SCRAP_WRITEOFF';

    public function policyField(): string
    {
        return match ($this) {
            self::SalesDespatch => 'negative_stock_sales_despatch_enabled',
            self::MaterialConsumption => 'negative_stock_material_consumption_enabled',
            self::SupplierReturn => 'negative_stock_supplier_return_enabled',
            self::ScrapWriteoff => 'negative_stock_scrap_writeoff_enabled',
        };
    }
}
