import {
  useLayoutEffect,
  useRef,
  useState,
  type ComponentType,
  type CSSProperties,
} from "react";
import { createPortal } from "react-dom";
import { MapPin, Phone, Mail, Globe } from "lucide-react";
import {
  useCompanyShow,
  type PurchaseOrderResource,
  type SupplierAccountResource,
} from "@erp/core-api";
import { asCompanyView } from "../company/company-types";
import { usePrintSettingsStore } from "../../stores/print-settings-store";
import {
  buildPurchaseOrderPrintDocument,
  printDecimal,
  type PrintParty,
  type PurchaseOrderPrintDocument,
} from "./purchase-order-print-model";
import "./purchase-order-print.css";

export type PurchaseOrderPrintSection =
  | "header"
  | "parties"
  | "metadata"
  | "lines"
  | "summary"
  | "terms"
  | "signatures"
  | "footer";
export interface PurchaseOrderPrintTemplateDefinition {
  version: 1;
  id?: string;
  label?: string;
  sections: readonly PurchaseOrderPrintSection[];
  renderers?: Partial<
    Record<PurchaseOrderPrintSection, ComponentType<SectionProps>>
  >;
}
export const DEFAULT_PO_PRINT_TEMPLATE: PurchaseOrderPrintTemplateDefinition = {
  version: 1,
  id: "template-1",
  label: "Template 1 — Classic purchase order",
  sections: [
    "header",
    "parties",
    "metadata",
    "lines",
    "summary",
    "terms",
    "signatures",
    "footer",
  ],
};
type SectionProps = {
  document: PurchaseOrderPrintDocument;
  pageNumber?: number;
  pageCount?: number;
};

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
function CompanyLogo({ document: d }: SectionProps) {
  return d.company.logoUrl ? (
    <img src={d.company.logoUrl} alt="Company logo" />
  ) : (
    <svg
      className="po-print-demo-logo"
      viewBox="0 0 74 66"
      role="img"
      aria-label="Demo company logo"
    >
      <path fill="#1685ff" d="M3 4h17l26 29-26 29H3l26-29z" />
      <path fill="#07172e" d="M27 25 47 4h22v58H52V23L39 37z" />
    </svg>
  );
}
function Header({ document: d }: SectionProps) {
  const icons = { Phone, Email: Mail, Web: Globe };
  return (
    <header className="po-print-header">
      <div className="po-print-letterhead">
        <div className="po-print-brand">
          <CompanyLogo document={d} />
          <div>
            <div className="po-print-company">{d.company.name}</div>
            <div className="po-print-brand-caption">
              {d.company.legalName || "Company"}
            </div>
          </div>
        </div>
        {d.company.legalName && (
          <div className="po-print-legal-name">{d.company.legalName}</div>
        )}
        {d.company.address.length > 0 && (
          <address className="po-print-contact-line">
            <MapPin />
            <span>{d.company.address.join(", ")}</span>
          </address>
        )}
        {d.company.fields
          .filter((field) => field.label in icons)
          .map((field) => {
            const Icon = icons[field.label as keyof typeof icons];
            return (
              <div className="po-print-contact-line" key={field.label}>
                <Icon />
                <span>{field.value}</span>
              </div>
            );
          })}
        <dl className="po-print-registration">
          {d.company.fields
            .filter((field) => !(field.label in icons))
            .map((field) => (
              <div key={field.label}>
                <dt>{field.label}</dt>
                <dd>{field.value}</dd>
              </div>
            ))}
        </dl>
      </div>
      <div className="po-print-identity">
        <h1>Purchase order</h1>
        <div className="po-print-reference">
          <span className="po-print-status">
            {d.status.replaceAll("_", " ")}
          </span>
          <strong>{d.number}</strong>
        </div>
        {d.draft && <div className="po-print-draft">Draft — not issued</div>}
      </div>
    </header>
  );
}
function Parties({ document: d }: SectionProps) {
  return (
    <div className="po-print-parties">
      <Party title="Supplier" party={d.supplier} />
      {d.delivery ? (
        <Party title="Deliver to" party={d.delivery} />
      ) : (
        <section className="po-print-party po-print-delivery">
          <h2>Deliver to</h2>
          <p>Delivery address not specified</p>
        </section>
      )}
    </div>
  );
}
function Metadata({ document: d }: SectionProps) {
  return (
    <section className="po-print-order-details">
      <h2>PO details</h2>
      <dl className="po-print-meta">
        {d.metadata.map((f) => (
          <div key={f.label}>
            <dt>{f.label}</dt>
            <dd>{f.value}</dd>
          </div>
        ))}
      </dl>
    </section>
  );
}
function Lines({ document: d, pageNumber = 1, pageCount = 1 }: SectionProps) {
  const hasDiscount =
    d.commercial && d.lines.some((line) => /[1-9]/.test(line.discount ?? ""));
  const hasTax =
    d.commercial && d.lines.some((line) => /[1-9]/.test(line.tax ?? ""));
  return (
    <section className="po-print-lines">
      <div className="po-print-items-band">
        <h2>Items ordered{pageNumber > 1 ? " (continued)" : ""}</h2>
        <span>
          Page {pageNumber} of {pageCount}
        </span>
      </div>
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
                {hasDiscount && (
                  <th className="po-print-numeric">
                    Discount
                    <br />
                    (amount)
                  </th>
                )}
                {hasTax && (
                  <th className="po-print-numeric">
                    Tax
                    <br />
                    (amount)
                  </th>
                )}
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
                <strong>{line.description || line.item}</strong>
                {line.description && (
                  <div className="po-print-item-code">Item {line.item}</div>
                )}
                {line.details.map((detail, i) => (
                  <small key={i}>{detail}</small>
                ))}
              </td>
              <td className="po-print-numeric">
                {printDecimal(line.quantity)}
              </td>
              <td>{line.unit}</td>
              {d.commercial && (
                <>
                  <td className="po-print-numeric">
                    {printDecimal(line.price)}
                    <small>per {line.priceBasis}</small>
                  </td>
                  {hasDiscount && (
                    <td className="po-print-numeric">
                      {printDecimal(line.discount)}
                    </td>
                  )}
                  {hasTax && (
                    <td className="po-print-numeric">
                      {printDecimal(line.tax)}
                    </td>
                  )}
                  <td className="po-print-numeric">
                    {printDecimal(line.amount)}
                  </td>
                </>
              )}
            </tr>
          ))}
        </tbody>
      </table>
      {d.commercial && (
        <p className="po-print-table-note">
          Amounts in {d.currency || "order currency"}.
          {(hasDiscount || hasTax) &&
            " Discount and tax are amounts for the whole line."}
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
          <h2>Order total</h2>
          <div className="po-print-total-body">
            <span>{d.currency || "Order currency"}</span>
            <strong>{printDecimal(d.total)}</strong>
          </div>
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
function Footer({ document: d, pageNumber = 1, pageCount = 1 }: SectionProps) {
  return (
    <footer className="po-print-footer">
      <span>
        {d.footerNote ||
          (d.draft
            ? "Draft copy · not an issued purchase order"
            : "Purchase order copy")}
      </span>
      <span>
        {d.number} · Page {pageNumber} of {pageCount} · Printed {d.printedAt}
      </span>
    </footer>
  );
}
const sections: Record<
  PurchaseOrderPrintSection,
  ComponentType<SectionProps>
> = {
  header: Header,
  parties: Parties,
  metadata: Metadata,
  lines: Lines,
  summary: Summary,
  terms: Terms,
  signatures: Signatures,
  footer: Footer,
};

export const PURCHASE_ORDER_PRINT_TEMPLATES = [
  DEFAULT_PO_PRINT_TEMPLATE,
] as const;
export function resolvePurchaseOrderPrintTemplate(id?: string) {
  return (
    PURCHASE_ORDER_PRINT_TEMPLATES.find((template) => template.id === id) ??
    DEFAULT_PO_PRINT_TEMPLATE
  );
}

/** The probe uses the same A4 width and section renderers as the paper. Page counts come from measured rows, never a fixed row count. */
export function PurchaseOrderPrintTemplate({
  document: d,
  template = DEFAULT_PO_PRINT_TEMPLATE,
  overrides = {},
  accentColor = "#183b56",
}: {
  document: PurchaseOrderPrintDocument;
  template?: PurchaseOrderPrintTemplateDefinition;
  overrides?: Partial<
    Record<PurchaseOrderPrintSection, ComponentType<SectionProps>>
  >;
  accentColor?: string;
}) {
  const probe = useRef<HTMLDivElement>(null);
  const [pages, setPages] = useState<number[][]>([
    d.lines.map((_, index) => index),
  ]);
  const [ready, setReady] = useState(false);
  const style = { "--po-print-accent": accentColor } as CSSProperties;
  const render = (
    id: PurchaseOrderPrintSection,
    document = d,
    pageNumber = 1,
    pageCount = 1,
  ) => {
    if (!template.sections.includes(id)) return null;
    const Section = overrides[id] ?? template.renderers?.[id] ?? sections[id];
    return (
      <Section
        document={document}
        pageNumber={pageNumber}
        pageCount={pageCount}
      />
    );
  };
  useLayoutEffect(() => {
    const element = probe.current;
    if (!element) return;
    let disposed = false;
    const measure = () => {
      if (disposed) return;
      const height = (selector: string) =>
        element.querySelector(selector)?.getBoundingClientRect().height ?? 0;
      const rows = Array.from(
        element.querySelectorAll("tbody tr"),
        (row) => row.getBoundingClientRect().height,
      );
      // A4 at CSS 96dpi, less the 8mm top/bottom inset and footer clearance.
      const available = 1122.5 - 60.5 - height(".po-print-footer") - 18;
      const tableHead =
        height(".po-print-items-band") +
        height("thead") +
        height(".po-print-table-note") +
        18;
      const firstTop =
        height(".po-print-top") + height(".po-print-parties") + 28;
      const continuedTop = height(".po-print-continuation") + 18;
      const tail = height(".po-print-tail") + 16;
      const result: number[][] = [[]];
      let remaining = available - firstTop - tableHead;
      rows.forEach((rowHeight, index) => {
        if (
          rowHeight > remaining &&
          (result[result.length - 1]!.length || result.length === 1)
        ) {
          result.push([]);
          remaining = available - continuedTop - tableHead;
        }
        result[result.length - 1]!.push(index);
        remaining -= rowHeight;
      });
      if (tail > remaining) result.push([]);
      setPages((previous) =>
        JSON.stringify(previous) === JSON.stringify(result) ? previous : result,
      );
      setReady(true);
    };
    measure();
    void document.fonts.ready.then(measure);
    const images = Array.from(element.querySelectorAll("img"));
    images.forEach((img) => img.addEventListener("load", measure));
    return () => {
      disposed = true;
      images.forEach((img) => img.removeEventListener("load", measure));
    };
  }, [d, template, overrides]);
  const continuation = (
    <div className="po-print-continuation">
      <span>{d.company.legalName || d.company.name}</span>
      <span>
        Purchase order <strong>{d.number}</strong>
      </span>
    </div>
  );
  const top = (
    <div className="po-print-top">
      {render("header")}
      {render("metadata")}
    </div>
  );
  const tail = (
    <div className="po-print-tail">
      {render("summary")}
      {render("terms")}
      {render("signatures")}
    </div>
  );
  return (
    <>
      {createPortal(
        <div
          className="po-print po-print-probe"
          data-template={template.id}
          ref={probe}
          style={style}
          aria-hidden="true"
        >
          {top}
          {render("parties")}
          {continuation}
          {render("lines")}
          {tail}
          {render("footer")}
        </div>,
        document.body,
      )}
      <article
        className="po-print"
        data-testid="purchase-order-print"
        data-template={template.id}
        data-pagination-ready={ready}
        style={style}
      >
        {pages.map((indices, index) => (
          <section className="po-print-page" key={index} data-page={index + 1}>
            {index === 0 ? (
              <>
                {top}
                {render("parties")}
              </>
            ) : (
              continuation
            )}
            {indices.length > 0 &&
              render(
                "lines",
                {
                  ...d,
                  lines: indices
                    .map((line) => d.lines[line])
                    .filter(
                      (
                        line,
                      ): line is PurchaseOrderPrintDocument["lines"][number] =>
                        !!line,
                    ),
                },
                index + 1,
                pages.length,
              )}
            {index === pages.length - 1 && tail}
            {render("footer", d, index + 1, pages.length)}
          </section>
        ))}
      </article>
    </>
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
  const company =
    query.data?.status === 200
      ? asCompanyView(query.data.data.data)
      : undefined;
  const document = buildPurchaseOrderPrintDocument({
    ...props,
    settings,
    company,
    printedAt: new Intl.DateTimeFormat(undefined, {
      dateStyle: "medium",
      timeStyle: "short",
    }).format(new Date()),
  });
  return (
    <PurchaseOrderPrintTemplate
      document={document}
      accentColor={settings.accentColor}
      template={resolvePurchaseOrderPrintTemplate(
        settings.purchaseOrderTemplateId,
      )}
    />
  );
}
