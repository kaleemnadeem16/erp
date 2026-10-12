import { ExitDetailScreen } from './rejected-exit-detail-screen';
import { ExitFormScreen } from './rejected-exit-form-screen';
import { ExitListScreen } from './rejected-exit-list-screen';
import type { Presentation } from '../../lib/presentation';

/** The two documents share every screen; these are the routed entry points (ADR 0097). */
export const SupplierReturnsListScreen = () => <ExitListScreen kind="SUPPLIER_RETURN" />;
export const SupplierReturnFormScreen = ({ presentation }: { presentation?: Presentation } = {}) => (
  <ExitFormScreen kind="SUPPLIER_RETURN" presentation={presentation} />
);
export const SupplierReturnDetailScreen = ({ presentation }: { presentation?: Presentation } = {}) => (
  <ExitDetailScreen kind="SUPPLIER_RETURN" presentation={presentation} />
);
export const StockScrapsListScreen = () => <ExitListScreen kind="SCRAP" />;
export const StockScrapFormScreen = ({ presentation }: { presentation?: Presentation } = {}) => (
  <ExitFormScreen kind="SCRAP" presentation={presentation} />
);
export const StockScrapDetailScreen = ({ presentation }: { presentation?: Presentation } = {}) => (
  <ExitDetailScreen kind="SCRAP" presentation={presentation} />
);
