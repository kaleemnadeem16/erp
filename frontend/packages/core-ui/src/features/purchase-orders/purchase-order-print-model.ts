import { formatDateTime } from '../../lib/format-date';
import type { PurchaseOrderResource, SupplierAccountResource } from '@erp/core-api';
import type { CompanyView } from '../company/company-types';
import type { PrintSettings } from '../../stores/print-settings-store';

export interface PrintField {
  label: string;
  value: string;
}
export interface PrintParty {
  name: string;
  address: string[];
  fields: PrintField[];
}
/** JSON-compatible presentation data: a future authorised backend projection can supply this directly. */
export interface PurchaseOrderPrintDocument {
  version: 1;
  number: string;
  status: string;
  draft: boolean;
  company: PrintParty & { logoUrl?: string; legalName?: string };
  supplier: PrintParty;
  delivery?: PrintParty;
  metadata: PrintField[];
  currency: string;
  commercial: boolean;
  lines: {
    number: number;
    item: string;
    description: string;
    details: string[];
    quantity: string;
    unit: string;
    price?: string;
    priceBasis?: string;
    discount?: string;
    tax?: string;
    amount?: string;
  }[];
  total?: string;
  notes?: string;
  terms?: string[];
  signatures: string[];
  footerNote?: string;
  printedAt: string;
}

/** Adds separators without converting decimal strings to floats or changing precision. */
export function printDecimal(value?: string | null): string {
  if (!value) return '—';
  if (!/^-?\d+(\.\d+)?$/.test(value)) return value;
  const [integer = '', fraction] = value.split('.');
  return integer.replace(/\B(?=(\d{3})+(?!\d))/g, ',') + (fraction === undefined ? '' : `.${fraction}`);
}
const nonempty = (values: (string | null | undefined)[]) => values.filter((v): v is string => !!v);

export function buildPurchaseOrderPrintDocument(input: {
  order: PurchaseOrderResource;
  supplier?: SupplierAccountResource;
  company?: CompanyView;
  settings: PrintSettings;
  currency: string;
  commercial: boolean;
  uom: (id: number) => string;
  printedAt: string;
}): PurchaseOrderPrintDocument {
  const { order, supplier, company, settings, currency, commercial, uom, printedAt } = input;
  const values = company?.values ?? {};
  const value = (key: string) => (typeof values[key] === 'string' ? (values[key] as string) : undefined);
  const field = (label: string, val?: string | null): PrintField[] => (val ? [{ label, value: val }] : []);
  const site = order.supplier_site?.party_site;
  return {
    version: 1,
    number: order.number ?? `Draft #${order.id}`,
    status: order.status,
    draft: order.status === 'DRAFT',
    currency,
    commercial,
    company: {
      name: value('name') || settings.companyName || 'Company',
      legalName: value('legal_name') !== value('name') ? value('legal_name') : undefined,
      logoUrl: settings.companyLogoUrl || undefined,
      address: nonempty([
        value('address_line1'),
        value('address_line2'),
        nonempty([value('city'), value('state'), value('postal_code')]).join(', '),
        value('country_code'),
      ]),
      fields: [
        ...field('Phone', value('phone')),
        ...field('Email', value('email')),
        ...field('Web', value('website')),
        ...field('Registration', value('registration_number')),
        ...field('Tax ID', value('tax_number')),
        ...field('Sales tax', value('sales_tax_number')),
      ],
    },
    supplier: {
      name: supplier?.party?.name || supplier?.code || `Supplier #${order.supplier_account_id}`,
      address: site
        ? nonempty([
            site.address_line1,
            site.address_line2,
            nonempty([site.city, site.state, site.postal_code]).join(', '),
            site.country_code,
          ])
        : [],
      fields: [
        ...field('Supplier code', supplier?.code),
        ...field('Site', site?.name),
        ...field('Tax ID', supplier?.party?.tax_id),
        ...field('Registration', supplier?.party?.registration_no),
        ...field('Website', supplier?.party?.website),
      ],
    },
    metadata: [
      { label: 'Order date', value: order.order_date },
      { label: 'Expected delivery', value: order.expected_delivery_date ?? 'Not specified' },
      ...(supplier?.payment_terms_days !== undefined
        ? [{ label: 'Payment terms', value: `${supplier.payment_terms_days} days` }]
        : []),
      ...(currency ? [{ label: 'Currency', value: currency }] : []),
      ...(supplier?.lead_time_days != null ? [{ label: 'Lead time', value: `${supplier.lead_time_days} days` }] : []),
      ...field('Created', formatDateTime(order.created_at)),
      ...field('Issued', order.issued_at ? formatDateTime(order.issued_at) : undefined),
      ...field('External reference', order.source_record_id),
    ],
    lines: (order.lines ?? []).map((line) => ({
      number: line.line_number,
      item: line.item_code ?? `#${line.item_id}`,
      description: line.description,
      details: nonempty([
        line.supplier_item_code ? `Supplier code: ${line.supplier_item_code}` : undefined,
        line.package_name ? `Package: ${line.package_name}` : undefined,
        line.expected_delivery_date ? `Delivery: ${line.expected_delivery_date}` : undefined,
        line.qa_required ? 'Quality inspection required' : undefined,
        line.purchase_uom_id !== line.stock_uom_id
          ? `Stock equivalent: ${printDecimal(line.ordered_stock_qty)} ${uom(line.stock_uom_id)}`
          : undefined,
      ]),
      quantity: line.ordered_qty,
      unit: line.order_unit_label ?? uom(line.purchase_uom_id),
      ...(commercial
        ? {
            price: line.unit_price,
            priceBasis: `${printDecimal(line.price_quantity ?? '1')} ${line.price_unit_label ?? uom(line.price_uom_id ?? line.purchase_uom_id)}`,
            discount: line.discount_amount,
            tax: line.tax_amount,
            amount: line.line_amount,
          }
        : {}),
    })),
    ...(commercial && order.total_amount !== undefined ? { total: order.total_amount } : {}),
    notes: order.notes || undefined,
    signatures: settings.showSignatureLines ? ['Prepared by', 'Authorised signature', 'Supplier acknowledgement'] : [],
    footerNote: settings.footerNote || undefined,
    printedAt,
  };
}
