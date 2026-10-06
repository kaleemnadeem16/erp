import { useState } from 'react';
import { Collapse } from '../../components/ui/collapse';
import { Button } from '../../components/ui/button';
import type { ApprovalRequestResource } from '@erp/core-api';
import { DetailPrintLayout, PrintTrigger, useQuickPrint } from '../../components/print';
import { formatDateTime } from '../../lib/format-date';

/** Displays and prints only the server-projected submitted version; never fetches the live PO. */
export function ApprovalDocumentReview({ request }: { request: ApprovalRequestResource }) {
  const review = request.document_review;
  const [expandedReview, setExpandedReview] = useState<string | null>(null);
  const reviewKey = `${request.id}:${review?.profile}`;
  const expanded = expandedReview === reviewKey;
  const detailsId = `submitted-review-details-${request.id}`;
  const { contentRef, print } = useQuickPrint({
    documentTitle: `Approval review ${request.id}`,
  });
  if (!request.document_review_required) return null;
  if (!review?.available) {
    return (
      <p role="status" className="rounded-md border p-3 text-sm text-muted-foreground">
        {request.document_review_ready
          ? 'Document review is restricted to authorised reviewers.'
          : request.status === 'PENDING'
            ? 'The submitted document review is unavailable. Ask the submitter to withdraw and submit this document again.'
            : 'This older approval has no saved document review.'}
      </p>
    );
  }
  const sections = review.sections.map((section) => ({
    title: section.title,
    fields: section.fields.map((field) => ({
      label: field.label,
      value: field.value ?? '—',
    })),
  }));
  return (
    <section aria-label="Submitted document review" className="space-y-3 rounded-md border p-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h4 className="font-semibold">Submitted approval version</h4>
          <p className="text-xs text-muted-foreground">
            {review.profile === 'OPERATIONAL'
              ? 'Technical review — financial fields are restricted'
              : 'Complete commercial review'}
            {' · '}Saved {formatDateTime(review.captured_at)}
          </p>
        </div>
        <Button
          type="button"
          variant="outline"
          size="sm"
          aria-expanded={expanded}
          aria-controls={detailsId}
          onClick={() => setExpandedReview(expanded ? null : reviewKey)}
        >
          {expanded ? 'Hide review details' : 'Show review details'}
        </Button>
      </div>
      <Collapse open={expanded} id={detailsId}>
        <div className="space-y-3">
          <div className="flex justify-end">
            <PrintTrigger onPrint={print} label="Print submitted version" />
          </div>
          {sections.map((section) => (
            <section key={section.title} className="space-y-1">
              <h5 className="text-sm font-medium">{section.title}</h5>
              <dl className="grid gap-x-4 gap-y-2 text-sm sm:grid-cols-2">
                {section.fields.map((field) => (
                  <div key={field.label}>
                    <dt className="text-xs text-muted-foreground">{field.label}</dt>
                    <dd className="whitespace-pre-wrap break-words">{field.value}</dd>
                  </div>
                ))}
              </dl>
            </section>
          ))}
        </div>
      </Collapse>
      <div className="hidden">
        <div ref={contentRef}>
          <DetailPrintLayout title={`Approval review #${request.id}`} sections={sections} />
        </div>
      </div>
    </section>
  );
}
