import { useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router';
import { Plus } from 'lucide-react';
import { useCurrenciesIndex, useGoodsReceiptsIndex, useGoodsReceiptsShow } from '@erp/core-api';

import { Button } from '../../components/ui/button';
import { Combobox } from '../../components/ui/combobox';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { Skeleton } from '../../components/ui/skeleton';
import { Textarea } from '../../components/ui/textarea';
import { MoreOptionsToggle } from '../../components/ui/more-options-toggle';
import { RecordSheet } from '../../components/page/record-sheet';
import { ScreenTemplate } from '../../components/page/screen-template';
import { PageHeader } from '../../components/page/page-header';
import { DetailSection } from '../../components/detail/detail-page-layout';
import { getApiErrorMessage, getApiReasonCode, lookupState } from '../../lib/api-errors';
import type { Presentation } from '../../lib/presentation';
import { usePermission } from '../../hooks/use-permission';
import { trimQty } from '../package-stock/quantity';
import { REASON_HINTS } from '../package-stock/types';
import {
  EXIT_CONFIG,
  useExitApi,
  useExitDocument,
  useRejectedStock,
  type ExitBody,
  type ExitKind,
  type ExitLineInput,
  type PreviewLine,
} from './rejected-exit-api';
import { ExitLineCard, activeBlockers, effectivePortion, lineProblem, type DraftLine } from './rejected-exit-line-card';

let nextKey = 1;
const newKey = () => `line-${nextKey++}`;

/**
 * Create or edit one draft Supplier Return / Scrap. Delivery lines are chosen from a receipt's rejected stock; the preview shows, before anything is
 * saved, how much is still there, which packages would leave, and anything that would stop it. Saving a draft moves no stock — posting is on the
 * document's own page (ADR 0097).
 */
export function ExitFormScreen({ kind, presentation }: { kind: ExitKind; presentation?: Presentation }) {
  const config = EXIT_CONFIG[kind];
  const navigate = useNavigate();
  const params = useParams<{ id: string }>();
  const [search] = useSearchParams();
  const editingId = params.id ? Number(params.id) : 0;
  const mayCreate = usePermission(config.createPermission);
  const api = useExitApi(kind);
  const { query: documentQuery, document } = useExitDocument(kind, editingId);

  const [reason, setReason] = useState('');
  const [header, setHeader] = useState<Record<string, string>>({});
  const [optionsOpen, setOptionsOpen] = useState(false);
  const [lines, setLines] = useState<DraftLine[]>([]);
  const [previews, setPreviews] = useState<Record<number, PreviewLine>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [showErrors, setShowErrors] = useState(false);
  const [receiptId, setReceiptId] = useState(search.get('receipt') ?? '');
  // Which delivery and supplier each chosen line belongs to, learned as deliveries are opened.
  const [origins, setOrigins] = useState<Record<number, { supplierId: number; label: string }>>({});
  const loaded = useRef(false);

  // Reopening a draft: load its header and lines once.
  useEffect(() => {
    if (!document || loaded.current) return;
    loaded.current = true;
    setReason(document.reason ?? '');
    setHeader({
      supplier_reference: document.supplier_reference ?? '',
      carrier_note: document.carrier_note ?? '',
      expected_credit_amount: document.expected_credit_amount ?? '',
      expected_credit_currency_id: document.expected_credit_currency_id ? String(document.expected_credit_currency_id) : '',
      expected_credit_reference: document.expected_credit_reference ?? '',
      disposal_method: document.disposal_method ?? '',
      disposal_reference: document.disposal_reference ?? '',
    });
    setLines(
      (document.lines ?? []).map((line) => ({
        key: newKey(),
        goods_receipt_line_id: line.goods_receipt_line_id,
        quantity: line.quantity,
        outcomeChangeReason: line.outcome_change_reason ?? '',
        portion: line.package_portion,
      })),
    );
  }, [document]);

  // Arriving from a delivery's rejected stock: start with that one line.
  const requestedLine = Number(search.get('line') ?? 0);
  useEffect(() => {
    if (editingId || !requestedLine) return;
    setLines((current) =>
      current.some((line) => line.goods_receipt_line_id === requestedLine)
        ? current
        : [...current, { key: newKey(), goods_receipt_line_id: requestedLine, quantity: '', outcomeChangeReason: '', portion: null }],
    );
  }, [editingId, requestedLine]);

  // The system's proposal is sent back as the declared portion, so a grouped line previews (and saves) exactly the packages the person saw.
  const payload = useMemo<ExitLineInput[]>(
    () =>
      lines.map((line) => ({
        goods_receipt_line_id: line.goods_receipt_line_id,
        quantity: line.quantity.trim() || undefined,
        outcome_change_reason: line.outcomeChangeReason.trim() || undefined,
        package_portion: effectivePortion(line, previews[line.goods_receipt_line_id]) ?? undefined,
      })),
    [lines, previews],
  );
  const payloadKey = JSON.stringify(payload);

  // Preview whenever the lines change (debounced): this is what makes the document "preview before post".
  const runPreview = api.preview.run;
  useEffect(() => {
    if (payload.length === 0) {
      setPreviews({});
      return;
    }
    const handle = setTimeout(() => {
      runPreview(payload)
        .then((rows) => setPreviews(Object.fromEntries(rows.map((row) => [row.goods_receipt_line_id, row]))))
        .catch((error) => setFormError(getApiErrorMessage(error)));
    }, 250);

    return () => clearTimeout(handle);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [payloadKey]);

  // A line added without a quantity starts at everything that is still there.
  useEffect(() => {
    const empty = lines.filter((line) => line.quantity === '' && previews[line.goods_receipt_line_id]?.available);
    if (empty.length === 0) return;
    setLines((current) =>
      current.map((line) =>
        line.quantity === '' && previews[line.goods_receipt_line_id]?.available
          ? { ...line, quantity: trimQty(previews[line.goods_receipt_line_id]?.available) }
          : line,
      ),
    );
  }, [lines, previews]);

  const currencies = useCurrenciesIndex({ per_page: 100, sort: 'code' }, { query: { enabled: kind === 'SUPPLIER_RETURN' && optionsOpen } });
  const currencyRows = currencies.data?.status === 200 ? currencies.data.data.data : [];

  // The document is one supplier / one warehouse (ADR 0097). The server refuses a mix on save; saying so as soon as it is visible saves a round trip.
  const blockers = lines.flatMap((line) => activeBlockers(line, previews[line.goods_receipt_line_id]));
  const supplierIds = new Set(lines.map((line) => origins[line.goods_receipt_line_id]?.supplierId).filter((id): id is number => id !== undefined));
  const warehouseIds = new Set(lines.map((line) => previews[line.goods_receipt_line_id]?.warehouse_id).filter((id): id is number => id !== undefined));
  const mixed = kind === 'SUPPLIER_RETURN' && supplierIds.size > 1 ? 'MIXED_SUPPLIERS' : warehouseIds.size > 1 ? 'MIXED_WAREHOUSES' : null;
  const problems = lines.map((line) => lineProblem(line, previews[line.goods_receipt_line_id]));
  const reasonMissing = reason.trim() === '';
  // The API refuses an expected credit without both an amount and its currency; say so beside the fields instead of after saving.
  const creditAmount = header.expected_credit_amount?.trim() ?? '';
  const creditCurrency = header.expected_credit_currency_id?.trim() ?? '';
  const creditProblem =
    kind === 'SUPPLIER_RETURN' && ((creditAmount !== '' && creditCurrency === '') || (creditCurrency !== '' && creditAmount === '') || (creditAmount !== '' && !/^\d+(\.\d{1,4})?$/.test(creditAmount)))
      ? creditAmount !== '' && creditCurrency === ''
        ? 'Choose the currency of the expected credit.'
        : creditCurrency !== '' && creditAmount === ''
          ? 'Enter the expected credit amount, or clear the currency.'
          : 'Enter the amount as a number, up to four decimals.'
      : null;
  const readyToSend = lines.length > 0 && !reasonMissing && creditProblem === null && mixed === null && problems.every((problem) => problem === null);

  function setField(name: string, value: string) {
    setHeader((current) => ({ ...current, [name]: value }));
  }

  function body(): ExitBody {
    const text = (name: string) => header[name]?.trim() || null;
    if (kind === 'SUPPLIER_RETURN') {
      return {
        reason: reason.trim(),
        supplier_reference: text('supplier_reference'),
        carrier_note: text('carrier_note'),
        expected_credit_amount: text('expected_credit_amount'),
        expected_credit_currency_id: text('expected_credit_currency_id') ? Number(header.expected_credit_currency_id) : null,
        expected_credit_reference: text('expected_credit_reference'),
      };
    }

    return { reason: reason.trim(), disposal_method: text('disposal_method'), disposal_reference: text('disposal_reference') };
  }

  async function save() {
    setShowErrors(true);
    setFormError(null);
    if (creditProblem) setOptionsOpen(true);
    // What the person controls must be right; what the server decides (the proposed packages, remaining stock) is asked again below, so a preview that is
    // a moment behind the last keystroke can neither block nor wrongly allow the save.
    if (!readyToSend) return;
    try {
      const manual = lines.map((line) => ({
        goods_receipt_line_id: line.goods_receipt_line_id,
        quantity: line.quantity.trim(),
        outcome_change_reason: line.outcomeChangeReason.trim() || undefined,
        package_portion: line.portion ?? undefined,
      }));
      const proposed = await api.preview.run(manual);
      const toSave: ExitLineInput[] = manual.map((entry) => ({
        ...entry,
        package_portion: entry.package_portion ?? proposed.find((row) => row.goods_receipt_line_id === entry.goods_receipt_line_id)?.proposed_portion ?? undefined,
      }));
      const fresh = await api.preview.run(toSave);
      setPreviews(Object.fromEntries(fresh.map((row) => [row.goods_receipt_line_id, row])));
      const stopped = fresh.flatMap((row) => row.blockers)[0];
      if (stopped) {
        setFormError(REASON_HINTS[stopped.code] ?? stopped.message);
        return;
      }
      const result = editingId ? await api.update.run(editingId, body(), toSave) : await api.store.run(body(), toSave);
      const saved = (result as unknown as { data?: { data?: { id: number } } }).data?.data;
      navigate(`${config.basePath}/${saved?.id ?? editingId}`);
    } catch (error) {
      const code = getApiReasonCode(error);
      setFormError((code && REASON_HINTS[code]) || getApiErrorMessage(error));
    }
  }

  if (!mayCreate) {
    if (presentation === 'sheet') {
      return (
        <RecordSheet
          title={config.title}
          size="xl"
          className="erp-record"
          dismissOnOutsideClick={false}
          onClose={() => navigate({ pathname: config.basePath, search: search.toString() })}
        >
          <div className="space-y-4 p-4 md:p-6" data-testid="exit-denied">
            <p className="text-sm text-muted-foreground">You don't have permission to prepare a {config.noun}.</p>
          </div>
        </RecordSheet>
      );
    }
    return (
      <div className="space-y-4 p-4 md:p-6" data-testid="exit-denied">
        <PageHeader title={config.title} breadcrumbs={[{ label: config.plural, to: config.basePath }]} />
        <p className="text-sm text-muted-foreground">You don't have permission to prepare a {config.noun}.</p>
      </div>
    );
  }
  if (editingId && documentQuery.isLoading) {
    if (presentation === 'sheet') {
      return (
        <RecordSheet
          title={`Loading ${config.noun}…`}
          size="xl"
          className="erp-record"
          dismissOnOutsideClick={false}
          onClose={() => navigate({ pathname: config.basePath, search: search.toString() })}
        >
          <div className="space-y-4 p-4"><Skeleton className="h-64 w-full" /></div>
        </RecordSheet>
      );
    }
    return <Skeleton className="m-6 h-64" />;
  }
  if (editingId && document && document.status !== 'DRAFT') {
    if (presentation === 'sheet') {
      return (
        <RecordSheet
          title={document.number ?? config.title}
          size="xl"
          className="erp-record"
          dismissOnOutsideClick={false}
          onClose={() => navigate({ pathname: `${config.basePath}/${editingId}`, search: search.toString() })}
        >
          <div className="space-y-4 p-4 md:p-6" data-testid="exit-frozen">
            <p className="text-sm text-muted-foreground">{document.number ?? 'This document'} is {document.status.toLowerCase()} and can no longer be changed.</p>
            <Button variant="outline" onClick={() => navigate(`${config.basePath}/${editingId}`)}>View it</Button>
          </div>
        </RecordSheet>
      );
    }
    return (
      <div className="space-y-4 p-4 md:p-6" data-testid="exit-frozen">
        <PageHeader title={document.number ?? config.title} breadcrumbs={[{ label: config.plural, to: config.basePath }]} />
        <p className="text-sm text-muted-foreground">{document.number ?? 'This document'} is {document.status.toLowerCase()} and can no longer be changed.</p>
        <Button variant="outline" onClick={() => navigate(`${config.basePath}/${editingId}`)}>View it</Button>
      </div>
    );
  }
  if (editingId && documentQuery.isError) {
    return <p className="p-6 text-sm text-destructive">Couldn't load this {config.noun}.</p>;
  }

  const added = new Set(lines.map((line) => line.goods_receipt_line_id));

  const formBody = (
    <>
      {formError && <div role="alert" className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">{formError}</div>}

      <DetailSection title="Why" description="Required. This is kept with the document.">
        <div className="space-y-1 sm:col-span-2">
          <Label htmlFor="exit-reason">Reason</Label>
          <Textarea id="exit-reason" rows={2} value={reason} onChange={(event) => setReason(event.target.value)} aria-invalid={showErrors && reasonMissing} />
          {showErrors && reasonMissing && <p className="text-xs text-destructive">Say why this stock is {kind === 'SCRAP' ? 'being scrapped' : 'going back'}.</p>}
        </div>
        <div className="sm:col-span-2">
          <MoreOptionsToggle
            open={optionsOpen}
            onOpenChange={setOptionsOpen}
            controls="exit-more-options"
            label={kind === 'SUPPLIER_RETURN' ? 'Shipping and expected credit' : 'Disposal details'}
          />
        </div>
        {optionsOpen && (
          <div id="exit-more-options" className="grid gap-3 sm:col-span-2 sm:grid-cols-2">
            {kind === 'SUPPLIER_RETURN' ? (
              <>
                <TextField label="Supplier's reference" value={header.supplier_reference} onChange={(value) => setField('supplier_reference', value)} />
                <TextField label="Carrier note" value={header.carrier_note} onChange={(value) => setField('carrier_note', value)} />
                <p className="text-xs text-muted-foreground sm:col-span-2">
                  What you expect the supplier to credit. For your reference only — it is not booked anywhere and does not change stock.
                </p>
                <TextField label="Expected credit (information only)" inputMode="decimal" value={header.expected_credit_amount} onChange={(value) => setField('expected_credit_amount', value)} />
                <div className="space-y-1">
                  <Label>Currency</Label>
                  <Combobox
                    {...lookupState(currencies, 'currencies')}
                    aria-label="Currency"
                    value={header.expected_credit_currency_id ?? ''}
                    onValueChange={(value) => setField('expected_credit_currency_id', value)}
                    options={currencyRows.map((currency) => ({ value: String(currency.id), label: currency.code }))}
                    placeholder="Select a currency"
                    searchPlaceholder="Search currencies…"
                    emptyText="No currencies found."
                  />
                  {showErrors && creditProblem && <p className="text-xs text-destructive" data-testid="credit-problem">{creditProblem}</p>}
                </div>
                <TextField label="Credit note reference" value={header.expected_credit_reference} onChange={(value) => setField('expected_credit_reference', value)} />
              </>
            ) : (
              <>
                <TextField label="Disposal method" value={header.disposal_method} onChange={(value) => setField('disposal_method', value)} />
                <TextField label="Disposal reference" value={header.disposal_reference} onChange={(value) => setField('disposal_reference', value)} />
              </>
            )}
          </div>
        )}
      </DetailSection>

      <DetailSection title="Rejected stock to take out" description="Choose a delivery, then add the lines that still have rejected stock." fullWidth>
        <div className="space-y-3 sm:col-span-2">
          <ReceiptPicker
            kind={kind}
            receiptId={receiptId}
            onReceiptChange={setReceiptId}
            added={added}
            onLoaded={(found) => setOrigins((current) => ({ ...current, ...found }))}
            onAdd={(lineId) =>
              setLines((current) => [...current, { key: newKey(), goods_receipt_line_id: lineId, quantity: '', outcomeChangeReason: '', portion: null }])
            }
          />

          {mixed && (
            <p role="alert" className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive" data-testid="exit-mixed" data-reason-code={mixed}>
              {REASON_HINTS[mixed]}
            </p>
          )}

          {lines.length === 0 ? (
            <p className="rounded-md border border-dashed p-4 text-sm text-muted-foreground" data-testid="exit-no-lines">
              No lines yet. Choose a delivery above and add its rejected stock.
              {showErrors && <span className="block text-destructive">Add at least one line.</span>}
            </p>
          ) : (
            lines.map((line, index) => (
              <ExitLineCard
                key={line.key}
                config={config}
                line={{ ...line, source: origins[line.goods_receipt_line_id]?.label }}
                preview={previews[line.goods_receipt_line_id]}
                showErrors={showErrors}
                onChange={(next) => setLines((current) => current.map((entry, at) => (at === index ? next : entry)))}
                onRemove={() => setLines((current) => current.filter((_, at) => at !== index))}
              />
            ))
          )}
        </div>
      </DetailSection>

      <div className="sticky bottom-0 -mx-4 flex flex-wrap items-center justify-end gap-2 border-t bg-background p-3 md:static md:mx-0 md:border-0 md:p-0">
        {showErrors && !readyToSend && <span className="mr-auto text-sm text-destructive">Fix the points above to save.</span>}
        <Button variant="outline" onClick={() => navigate(editingId ? `${config.basePath}/${editingId}` : config.basePath)}>Cancel</Button>
        <Button onClick={() => void save()} disabled={api.store.isPending || api.update.isPending} data-testid="exit-save">
          {api.store.isPending || api.update.isPending ? 'Saving…' : 'Save draft'}
        </Button>
      </div>
    </>
  );

  const title = editingId ? `Edit ${config.noun} draft` : `New ${config.noun}`;
  const description =
    kind === 'SUPPLIER_RETURN'
      ? 'Send rejected stock back to the supplier. Nothing leaves your warehouse until you post.'
      : 'Write off rejected stock. Nothing leaves your warehouse until you post.';

  if (presentation === 'sheet') {
    return (
      <RecordSheet
        title={title}
        description={description}
        size="xl"
        className="erp-record"
        dismissOnOutsideClick={false}
        onClose={() => navigate({ pathname: editingId ? `${config.basePath}/${editingId}` : config.basePath, search: search.toString() })}
      >
        <div className="space-y-4 pb-4" data-testid="exit-form">
          {formBody}
        </div>
      </RecordSheet>
    );
  }

  return (
    <ScreenTemplate kind="form" className="erp-screen pb-24">
      <div className="space-y-4" data-testid="exit-form">
        <PageHeader
          title={title}
          description={description}
          breadcrumbs={[{ label: config.plural, to: config.basePath }, { label: editingId ? 'Edit' : 'New' }]}
        />
        {formBody}
      </div>
    </ScreenTemplate>
  );
}

function TextField({ label, value, onChange, inputMode }: { label: string; value: string | undefined; onChange: (value: string) => void; inputMode?: 'decimal' }) {
  const id = `exit-${label.toLowerCase().replace(/[^a-z]+/g, '-')}`;

  return (
    <div className="space-y-1">
      <Label htmlFor={id}>{label}</Label>
      <Input id={id} inputMode={inputMode} value={value ?? ''} onChange={(event) => onChange(event.target.value)} />
    </div>
  );
}

/** Pick a posted delivery and list its lines that still have rejected stock. */
function ReceiptPicker({
  kind,
  receiptId,
  onReceiptChange,
  added,
  onAdd,
  onLoaded,
}: {
  kind: ExitKind;
  receiptId: string;
  onReceiptChange: (value: string) => void;
  added: Set<number>;
  onAdd: (lineId: number, available: string) => void;
  onLoaded: (origins: Record<number, { supplierId: number; label: string }>) => void;
}) {
  const receipts = useGoodsReceiptsIndex({ 'filter[status]': 'POSTED', per_page: 100, sort: '-created_at' });
  const rows = receipts.data?.status === 200 ? receipts.data.data.data : [];
  const receipt = useGoodsReceiptsShow(Number(receiptId), { query: { enabled: !!receiptId } });
  const detail = receipt.data?.status === 200 ? receipt.data.data.data : undefined;
  useEffect(() => {
    if (!detail) return;
    onLoaded(Object.fromEntries((detail.lines ?? []).map((line) => [line.id, { supplierId: detail.supplier_account_id, label: `Delivery ${detail.number ?? `#${detail.id}`}` }])));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [detail]);

  return (
    <div className="space-y-2">
      <div className="space-y-1">
        <Label>Delivery</Label>
        <Combobox
          {...lookupState(receipts, 'deliveries')}
          aria-label="Delivery"
          value={receiptId}
          onValueChange={onReceiptChange}
          options={[
            ...rows.map((row) => ({ value: String(row.id), label: row.number ?? `Receipt #${row.id}` })),
            ...(receiptId && !rows.some((row) => String(row.id) === receiptId) ? [{ value: receiptId, label: `Receipt #${receiptId}` }] : []),
          ]}
          placeholder="Select a delivery"
          searchPlaceholder="Search deliveries…"
          emptyText="No posted deliveries found."
        />
      </div>
      {receiptId && receipt.isLoading && <Skeleton className="h-12" />}
      {detail && (detail.lines ?? []).length === 0 && <p className="text-sm text-muted-foreground">This delivery has no lines.</p>}
      {detail && (detail.lines ?? []).map((line) => (
        <PickableLine key={line.id} kind={kind} lineId={line.id} alreadyAdded={added.has(line.id)} onAdd={(available) => onAdd(line.id, available)} />
      ))}
    </div>
  );
}

function PickableLine({ kind, lineId, alreadyAdded, onAdd }: { kind: ExitKind; lineId: number; alreadyAdded: boolean; onAdd: (available: string) => void }) {
  const { query, preview } = useRejectedStock(lineId, kind, true);
  if (query.isLoading) return <Skeleton className="h-10" />;
  // A line the caller may not read (outside their warehouses) or with no stock is simply not offered.
  if (!preview || Number(preview.available ?? 0) <= 0) return null;

  return (
    <div className="flex flex-wrap items-center justify-between gap-2 rounded-md border p-2 text-sm" data-testid="pickable-line">
      <span className="min-w-0 break-words">
        {preview.item ? `${preview.item.code} — ${preview.item.name}` : `Line #${lineId}`}
        <span className="text-muted-foreground"> · {trimQty(preview.available)} rejected, still to take out</span>
      </span>
      <Button type="button" size="sm" variant="outline" disabled={alreadyAdded} onClick={() => onAdd(trimQty(preview.available))}>
        <Plus className="h-4 w-4" />
        {alreadyAdded ? 'Added' : 'Add'}
      </Button>
    </div>
  );
}
