import { ExitDetailScreen } from './rejected-exit-detail-screen';
import { ExitFormScreen } from './rejected-exit-form-screen';
import { ExitListScreen } from './rejected-exit-list-screen';

/** The two documents share every screen; these are the routed entry points (ADR 0097). */
export const SupplierReturnsListScreen = () => <ExitListScreen kind="SUPPLIER_RETURN" />;
export const SupplierReturnFormScreen = () => <ExitFormScreen kind="SUPPLIER_RETURN" />;
export const SupplierReturnDetailScreen = () => <ExitDetailScreen kind="SUPPLIER_RETURN" />;
export const StockScrapsListScreen = () => <ExitListScreen kind="SCRAP" />;
export const StockScrapFormScreen = () => <ExitFormScreen kind="SCRAP" />;
export const StockScrapDetailScreen = () => <ExitDetailScreen kind="SCRAP" />;
