import { AlertTriangle, CheckCircle2, XCircle } from 'lucide-react';

import { cn } from '../../lib/utils';
import type { NumberingPreviewResult } from './numbering-types';

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="space-y-0.5">
      <dt className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</dt>
      <dd className="text-sm">{children}</dd>
    </div>
  );
}

/**
 * The tester (ADR 0079 §15): what the format being edited would do — never takes a number. Everything it says is the server's:
 * the rendered example, which instant and time zone, the counter and when it restarts, the next numbers, and any conflict.
 */
export function NumberingTester({ result, loading, failed }: { result: NumberingPreviewResult | null; loading: boolean; failed: boolean }) {
  if (failed) {
    return <p role="alert" className="text-sm text-destructive">The preview could not be worked out. Try again in a moment.</p>;
  }
  if (result === null) {
    return <p className="text-sm text-muted-foreground">{loading ? 'Working out an example…' : 'Nothing to preview yet.'}</p>;
  }
  const blocked = result.errors.length > 0 || result.collisions.length > 0;

  return (
    <div className={cn('space-y-4 transition-opacity', loading && 'opacity-60')} aria-busy={loading || undefined}>
      <div className="space-y-1">
        <div className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Example</div>
        {result.example ? (
          <div className="break-all rounded-md border bg-card px-3 py-2 font-mono text-base font-semibold" data-testid="numbering-example">{result.example}</div>
        ) : (
          <div className="rounded-md border border-dashed px-3 py-2 text-sm text-muted-foreground">No example until the format is valid.</div>
        )}
      </div>

      {blocked ? (
        <div role="alert" className="space-y-1 rounded-md border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive">
          <div className="flex items-center gap-2 font-medium"><XCircle className="size-4" aria-hidden="true" />Cannot be saved as it is</div>
          <ul className="list-disc space-y-0.5 pl-5">
            {[...result.errors, ...result.collisions].map((message) => <li key={message}>{message}</li>)}
          </ul>
        </div>
      ) : (
        <div className="flex items-center gap-2 text-sm text-status-success"><CheckCircle2 className="size-4" aria-hidden="true" />No two counters can issue the same number.</div>
      )}
      {result.warnings.length > 0 && (
        <div className="space-y-1 rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-sm">
          <div className="flex items-center gap-2 font-medium"><AlertTriangle className="size-4" aria-hidden="true" />Worth a look</div>
          <ul className="list-disc space-y-0.5 pl-5">{result.warnings.map((message) => <li key={message}>{message}</li>)}</ul>
        </div>
      )}

      <dl className="space-y-3">
        <Row label="Date used">
          {result.date_basis}
          <span className="block text-xs text-muted-foreground">Company time zone: {result.timezone}{result.local_date ? ` · simulated day ${result.local_date}` : ''}</span>
        </Row>
        <Row label="Counter">{result.counter_scope}<span className="block text-xs text-muted-foreground">{result.reset}</span></Row>
        {result.next_numbers.length > 0 && <Row label="Next numbers"><span className="font-mono">{result.next_numbers.join('  ·  ')}</span></Row>}
        {result.first_of_next_period && <Row label="First number of the next period"><span className="font-mono">{result.first_of_next_period}</span></Row>}
        <Row label="Policy">{result.continuity_text}</Row>
        <Row label="Length">{result.length} of {result.max_length} characters</Row>
        {result.change_impact && <Row label="If you save this">{result.change_impact}</Row>}
      </dl>
    </div>
  );
}
