import type { ComponentType, CSSProperties } from 'react';
import { useCompanyShow, type PurchaseOrderResource, type SupplierAccountResource } from '@erp/core-api';
import { asCompanyView } from '../company/company-types';
import { usePrintSettingsStore } from '../../stores/print-settings-store';
import {
  buildPurchaseOrderPrintDocument,
  printDecimal,
  type PrintParty,
  type PurchaseOrderPrintDocument,
} from './purchase-order-print-model';
import './purchase-order-print.css';

export type PurchaseOrderPrintSection =
  'header' | 'parties' | 'metadata' | 'lines' | 'summary' | 'terms' | 'signatures' | 'footer';
export interface PurchaseOrderPrintTemplateDefinition {
  version: 1;
  sections: readonly PurchaseOrderPrintSection[];
}
export const DEFAULT_PO_PRINT_TEMPLATE: PurchaseOrderPrintTemplateDefinition = {
  version: 1,
  sections: ['header', 'parties', 'metadata', 'lines', 'summary', 'terms', 'signatures', 'footer'],
};
type SectionProps = { document: PurchaseOrderPrintDocument };

function Party({ party, title }: { party: PrintParty; title: string }) {
  return (
    <section className="po-print-party">
      <h2>{title}</h2>
      <strong>{party.name}</strong>
      {party.address.length > 0 && (
        <address>
          {party.address.map((line, i) => (
            <div key={i}>{line}</div>
          ))}
        </address>
      )}
      <dl>
        {party.fields.map((field) => (
          <div key={field.label}>
            <dt>{field.label}</dt>
            <dd>{field.value}</dd>
          </div>
        ))}
      </dl>
    </section>
  );
}
function Header({ document: d }: SectionProps) {
  return (
    <header className="po-print-header">
      <div className="po-print-letterhead">
        {d.company.logoUrl && <img src={d.company.logoUrl} alt="Company logo" />}
        <div>
          <div className="po-print-company">{d.company.name}</div>
          {d.company.legalName && <div>{d.company.legalName}</div>}
          {d.company.address.length > 0 && <address>{d.company.address.join(' · ')}</address>}
          <div className="po-print-contact">
            {d.company.fields.map((f) => (
              <span key={f.label}>
                {f.label}: {f.value}
              </span>
            ))}
          </div>
        </div>
      </div>
      <div className="po-print-identity">
        <div className="po-print-eyebrow">Purchasing</div>
        <h1>Purchase order</h1>
        <div className="po-print-number">{d.number}</div>
        <div className="po-print-status">{d.status.replaceAll('_', ' ')}</div>
        {d.draft && <div className="po-print-draft">Draft — not issued</div>}
      </div>
    </header>
  );
}
function Parties({ document: d }: SectionProps) {
  return (
    <div className="po-print-parties">
      <Party title="Supplier" party={d.supplier} />
      {d.delivery && <Party title="Deliver to" party={d.delivery} />}
    </div>
  );
}
function Metadata({ document: d }: SectionProps) {
  return (
    <dl className="po-print-meta">
      {d.metadata.map((f) => (
        <div key={f.label}>
          <dt>{f.label}</dt>
          <dd>{f.value}</dd>
        </div>
      ))}
    </dl>
  );
}
function Lines({ document: d }: SectionProps) {
  return (
    <section className="po-print-lines">
      <h2>Items ordered</h2>
      <table>
        <thead>
          <tr>
            <th className="po-print-seq">#</th>
            <th>Item / specification</th>
            <th className="po-print-numeric">Quantity</th>
            <th>Unit</th>
            {d.commercial && (
              <>
                <th className="po-print-numeric">Unit price</th>
                <th className="po-print-numeric">
                  Discount
                  <br />
                  (amount)
                </th>
                <th className="po-print-numeric">
                  Tax
                  <br />
                  (amount)
                </th>
                <th className="po-print-numeric">Amount</th>
              </>
            )}
          </tr>
        </thead>
        <tbody>
          {d.lines.map((line) => (
            <tr key={line.number}>
              <td>{line.number}</td>
              <td className="po-print-description">
                <strong>{line.item}</strong>
                <div>{line.description}</div>
                {line.details.map((detail, i) => (
                  <small key={i}>{detail}</small>
                ))}
              </td>
              <td className="po-print-numeric">{printDecimal(line.quantity)}</td>
              <td>{line.unit}</td>
              {d.commercial && (
                <>
                  <td className="po-print-numeric">
                    {printDecimal(line.price)}
                    <small>per {line.priceBasis}</small>
                  </td>
                  <td className="po-print-numeric">{printDecimal(line.discount)}</td>
                  <td className="po-print-numeric">{printDecimal(line.tax)}</td>
                  <td className="po-print-numeric">{printDecimal(line.amount)}</td>
                </>
              )}
            </tr>
          ))}
        </tbody>
      </table>
      {d.commercial && (
        <p className="po-print-table-note">
          Amounts in {d.currency || 'order currency'}. Discount and tax are amounts for the whole line.
        </p>
      )}
    </section>
  );
}
function Summary({ document: d }: SectionProps) {
  return (
    <div className="po-print-summary">
      {d.notes && (
        <section className="po-print-notes">
          <h2>Order notes</h2>
          <p>{d.notes}</p>
        </section>
      )}
      {d.commercial && d.total !== undefined && (
        <section className="po-print-total">
          <span>Total ({d.currency || 'order currency'})</span>
          <strong>{printDecimal(d.total)}</strong>
        </section>
      )}
    </div>
  );
}
function Terms({ document: d }: SectionProps) {
  return d.terms?.length ? (
    <section className="po-print-terms">
      <h2>Terms and conditions</h2>
      <ol>
        {d.terms.map((term, i) => (
          <li key={i}>{term}</li>
        ))}
      </ol>
    </section>
  ) : null;
}
function Signatures({ document: d }: SectionProps) {
  return d.signatures.length ? (
    <div className="po-print-signatures">
      {d.signatures.map((label) => (
        <div key={label}>
          <span>{label}</span>
          <small>Name / date</small>
        </div>
      ))}
    </div>
  ) : null;
}
function Footer({ document: d }: SectionProps) {
  return (
    <footer className="po-print-footer">
      <span>{d.footerNote || (d.draft ? 'Draft copy · not an issued purchase order' : 'Purchase order copy')}</span>
      <span>
        {d.number} · Printed {d.printedAt}
      </span>
    </footer>
  );
}
const sections: Record<PurchaseOrderPrintSection, ComponentType<SectionProps>> = {
  header: Header,
  parties: Parties,
  metadata: Metadata,
  lines: Lines,
  summary: Summary,
  terms: Terms,
  signatures: Signatures,
  footer: Footer,
};

/** Named section renderers are replaceable; the template definition and document contain data, never executable HTML. */
export function PurchaseOrderPrintTemplate({
  document,
  template = DEFAULT_PO_PRINT_TEMPLATE,
  overrides = {},
  accentColor = '#183b56',
}: {
  document: PurchaseOrderPrintDocument;
  template?: PurchaseOrderPrintTemplateDefinition;
  overrides?: Partial<Record<PurchaseOrderPrintSection, ComponentType<SectionProps>>>;
  accentColor?: string;
}) {
  return (
    <article
      className="po-print"
      data-testid="purchase-order-print"
      style={{ '--po-print-accent': accentColor } as CSSProperties}
    >
      {template.sections.map((id) => {
        const Section = overrides[id] ?? sections[id];
        return Section ? <Section key={id} document={document} /> : null;
      })}
    </article>
  );
}

/** Current API adapter. The pure template above can later receive a backend document/template directly. */
export function PurchaseOrderQuickPrint(props: {
  order: PurchaseOrderResource;
  supplier?: SupplierAccountResource;
  currency: string;
  commercial: boolean;
  uom: (id: number) => string;
}) {
  const query = useCompanyShow({ query: { staleTime: 5 * 60_000 } });
  const settings = usePrintSettingsStore();
  const company = query.data?.status === 200 ? asCompanyView(query.data.data.data) : undefined;
  const document = buildPurchaseOrderPrintDocument({
    ...props,
    settings,
    company,
    printedAt: new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date()),
  });
  return <PurchaseOrderPrintTemplate document={document} accentColor={settings.accentColor} />;
}
