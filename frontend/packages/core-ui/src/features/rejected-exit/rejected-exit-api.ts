import {
  useRejectedStockShow,
  useStockScrapsCancel,
  useStockScrapsIndex,
  useStockScrapsPost,
  useStockScrapsPreview,
  useStockScrapsShow,
  useStockScrapsStore,
  useStockScrapsUpdate,
  useSupplierReturnsCancel,
  useSupplierReturnsIndex,
  useSupplierReturnsPost,
  useSupplierReturnsPreview,
  useSupplierReturnsShow,
  useSupplierReturnsStore,
  useSupplierReturnsUpdate,
} from '@erp/core-api';

import type { SealedRow } from '../package-stock/types';

/*
 * Supplier Return and Scrap are two documents over one exit core (ADR 0097), so their screens are shared and take a `kind`. The two generated hook
 * families have identical shapes apart from the body fields, so this adapter calls both and exposes whichever the kind selects. The preview and
 * rejected-stock responses are untyped JSON in the OpenAPI document; they are narrowed here, once, to the shapes PreviewRejectedStockExit returns.
 */
export type ExitKind = 'SUPPLIER_RETURN' | 'SCRAP';

export interface ExitConfig {
  kind: ExitKind;
  /** "Supplier Return" / "Scrap" — the document's name in headings. */
  title: string;
  plural: string;
  /** Lower-case noun used in sentences. */
  noun: string;
  basePath: string;
  createPermission: string;
  postPermission: string;
  numberPrefix: string;
  /** What posting does, in the words a storekeeper uses. */
  consequence: string;
}

export const EXIT_CONFIG: Record<ExitKind, ExitConfig> = {
  SUPPLIER_RETURN: {
    kind: 'SUPPLIER_RETURN',
    title: 'Supplier Return',
    plural: 'Supplier Returns',
    noun: 'supplier return',
    basePath: '/supplier-returns',
    createPermission: 'purchasing.supplier-return.create',
    postPermission: 'purchasing.supplier-return.post',
    numberPrefix: 'SRN',
    consequence: 'The rejected stock leaves your warehouse and goes back to the supplier. This cannot be undone.',
  },
  SCRAP: {
    kind: 'SCRAP',
    title: 'Scrap',
    plural: 'Scrap',
    noun: 'scrap',
    basePath: '/stock-scraps',
    createPermission: 'inventory.scrap.create',
    postPermission: 'inventory.scrap.post',
    numberPrefix: 'SCR',
    consequence: 'The rejected stock is written off and leaves your warehouse. This cannot be undone.',
  },
};

export interface PortionLine {
  kind: 'SEALED' | 'LOOSE';
  packaging_id?: number | null;
  count?: number | null;
  quantity?: string | null;
}

export interface ExitLineRecord {
  id: number;
  line_no?: number;
  goods_receipt_line_id: number;
  item_id: number;
  /** Embedded by the server, so archived items still show their code and name. */
  item?: { id: number; code: string | null; name: string | null };
  location_id: number;
  quantity: string;
  outcome_change_reason: string | null;
  package_portion: PortionLine[] | null;
  rejected_stock_exit_id: number | null;
}

export interface ExitDocument {
  id: number;
  number: string | null;
  status: 'DRAFT' | 'POSTED' | 'CANCELLED';
  warehouse_id: number;
  reason: string;
  supplier_account_id?: number;
  supplier_reference?: string | null;
  carrier_note?: string | null;
  expected_credit_amount?: string | null;
  expected_credit_currency_id?: number | null;
  expected_credit_reference?: string | null;
  disposal_method?: string | null;
  disposal_reference?: string | null;
  created_by: number | null;
  created_at: string | null;
  posted_at: string | null;
  cancelled_at: string | null;
  lines?: ExitLineRecord[];
}

export interface Blocker {
  code: string;
  message: string;
}

/** One preview row: what is rejected on a delivery line, what the document would take, and what stops it. */
export interface PreviewLine {
  goods_receipt_line_id: number;
  item?: { id: number; code: string; name: string };
  location_id?: number;
  warehouse_id?: number;
  grouped?: boolean;
  rejected?: string;
  exited?: string;
  available?: string;
  decided_for_kind?: string;
  requested?: string | null;
  remaining_after?: string | null;
  differing_qty?: string | null;
  outcome_change_required?: boolean;
  rejected_packages?: { sealed: SealedRow[]; loose: string; total: string };
  proposed_portion?: PortionLine[] | null;
  portion_message?: string | null;
  /** `unit_cost` is null unless the caller holds inventory.valuation.view. */
  cost?: { basis: 'LAYER' | 'POOL_AVERAGE'; unit_cost: string | null };
  blockers: Blocker[];
}

export interface ExitBody {
  reason: string;
  [key: string]: unknown;
}

export interface ExitLineInput {
  goods_receipt_line_id: number;
  quantity?: string;
  outcome_change_reason?: string;
  package_portion?: PortionLine[];
}

type Envelope<T> = { status: number; data: { data: T } } | undefined;

function unwrap<T>(response: unknown): T | undefined {
  const typed = response as Envelope<T>;

  return typed?.status === 200 ? typed.data.data : undefined;
}

export function useExitApi(kind: ExitKind) {
  const returns = kind === 'SUPPLIER_RETURN';
  const storeReturn = useSupplierReturnsStore();
  const storeScrap = useStockScrapsStore();
  const updateReturn = useSupplierReturnsUpdate();
  const updateScrap = useStockScrapsUpdate();
  const postReturn = useSupplierReturnsPost();
  const postScrap = useStockScrapsPost();
  const cancelReturn = useSupplierReturnsCancel();
  const cancelScrap = useStockScrapsCancel();
  const previewReturn = useSupplierReturnsPreview();
  const previewScrap = useStockScrapsPreview();

  // The generated bodies differ only in their header fields; ExitBody is the union the forms build, so one cast covers every call here.
  return {
    store: {
      isPending: returns ? storeReturn.isPending : storeScrap.isPending,
      run: (body: ExitBody, lines: ExitLineInput[]) =>
        returns
          ? storeReturn.mutateAsync({ data: { ...body, lines } as never })
          : storeScrap.mutateAsync({ data: { ...body, lines } as never }),
    },
    update: {
      isPending: returns ? updateReturn.isPending : updateScrap.isPending,
      run: (id: number, body: ExitBody, lines: ExitLineInput[]) =>
        returns
          ? updateReturn.mutateAsync({ supplierReturn: id, data: { ...body, lines } as never })
          : updateScrap.mutateAsync({ stockScrap: id, data: { ...body, lines } as never }),
    },
    post: {
      isPending: returns ? postReturn.isPending : postScrap.isPending,
      run: (id: number) => (returns ? postReturn.mutateAsync({ supplierReturn: id }) : postScrap.mutateAsync({ stockScrap: id })),
    },
    cancel: {
      isPending: returns ? cancelReturn.isPending : cancelScrap.isPending,
      run: (id: number) => (returns ? cancelReturn.mutateAsync({ supplierReturn: id }) : cancelScrap.mutateAsync({ stockScrap: id })),
    },
    preview: {
      isPending: returns ? previewReturn.isPending : previewScrap.isPending,
      run: async (lines: ExitLineInput[]): Promise<PreviewLine[]> => {
        const response = returns
          ? await previewReturn.mutateAsync({ data: { lines } as never })
          : await previewScrap.mutateAsync({ data: { lines } as never });

        return unwrap<PreviewLine[]>(response) ?? [];
      },
    },
  };
}

export function useExitDocument(kind: ExitKind, id: number) {
  const returns = kind === 'SUPPLIER_RETURN';
  const supplierReturn = useSupplierReturnsShow(id, { query: { enabled: returns && id > 0 } });
  const scrap = useStockScrapsShow(id, { query: { enabled: !returns && id > 0 } });
  const active = returns ? supplierReturn : scrap;

  return { query: active, document: unwrap<ExitDocument>(active.data) };
}

export function useExitIndex(
  kind: ExitKind,
  params: { warehouseId?: number; status?: string; search?: string; supplierAccountId?: number; createdFrom?: string; createdTo?: string; sort?: string; perPage: number; page: number },
) {
  const query = {
    'filter[warehouse_id]': params.warehouseId,
    'filter[status]': (params.status || undefined) as never,
    'filter[search]': params.search || undefined,
    'filter[created_from]': params.createdFrom,
    'filter[created_to]': params.createdTo,
    sort: params.sort as never,
    include: 'warehouse',
    per_page: params.perPage,
    page: params.page,
  };
  const returns = kind === 'SUPPLIER_RETURN';
  const supplierReturns = useSupplierReturnsIndex({ ...query, 'filter[supplier_account_id]': params.supplierAccountId }, { query: { enabled: returns } });
  const scraps = useStockScrapsIndex(query, { query: { enabled: !returns } });
  const active = returns ? supplierReturns : scraps;
  const body = (active.data as unknown as { status: number; data: { data: Array<ExitDocument & { warehouse?: { name: string } }>; meta?: { total: number } } } | undefined);
  const ok = body?.status === 200 ? body.data : undefined;

  return { query: active, rows: ok?.data ?? [], total: ok?.meta?.total ?? 0 };
}

/** What is rejected and still on hand for one delivery line, ignoring any requested quantity. Undefined while loading or when the caller may not read it. */
export function useRejectedStock(goodsReceiptLineId: number, kind: ExitKind, enabled: boolean) {
  const query = useRejectedStockShow(
    goodsReceiptLineId,
    { kind, quantity: undefined } as never,
    { query: { enabled, retry: false } },
  );

  return { query, preview: unwrap<PreviewLine>(query.data) };
}
