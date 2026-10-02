import { useState } from 'react';
import { Outlet, useNavigate } from 'react-router';
import { Hash } from 'lucide-react';

import { ListError } from '../../components/data-table';
import { PageHeader } from '../../components/page/page-header';
import { RecordSheet } from '../../components/page/record-sheet';
import { AdvancedOptionsMenu, type AdvancedOption } from '../../components/ui/advanced-options-menu';
import { Badge } from '../../components/ui/badge';
import { Button } from '../../components/ui/button';
import { Skeleton } from '../../components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '../../components/ui/table';
import { useParams } from 'react-router';
import { NumberFormatEditor } from './number-format-editor';
import { useNumberingInfo, useNumberingTypes } from './numbering-api';
import { NumberingConstantsPanel } from './numbering-constants-panel';
import { CONTINUITY_LABELS, periodLabel } from './numbering-types';

/**
 * Settings → Document Numbering (ADR 0079): every numbered document type, how its numbers are built now, and an example. The one
 * place for numbering — modules link here rather than keeping numbering settings of their own. Changing a type opens its editor.
 */
export function NumberingSettingsScreen() {
  const navigate = useNavigate();
  const { query, types } = useNumberingTypes();
  const { info } = useNumberingInfo();
  const [showConstants, setShowConstants] = useState(false);

  return (
    <div className="space-y-4 p-4 md:p-6">
      <PageHeader
        title="Document numbering"
        description="How the numbers on your documents are built. A new format applies to numbers issued from then on; numbers already issued never change."
        actions={<Button type="button" variant="outline" size="sm" aria-pressed={showConstants} onClick={() => setShowConstants((value) => !value)}>{showConstants ? 'Hide company constants' : 'Company constants'}</Button>}
      />
      {info?.timezone_is_default && (
        <p role="note" className="rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-sm">
          Dates in numbers use your company time zone, which is still the default, <strong>UTC</strong>. If your business day follows another
          time zone, set it first (Inventory settings) so a document issued late in the evening is numbered into the right day.
        </p>
      )}

      {query.isError ? (
        <ListError message="Couldn't load the document types." error={query.error} onRetry={() => void query.refetch()} />
      ) : (
        <div className="rounded-md border border-border">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Document</TableHead>
                <TableHead>Format</TableHead>
                <TableHead>Example</TableHead>
                <TableHead>Starts again</TableHead>
                <TableHead>Gaps</TableHead>
                <TableHead><span className="sr-only">Actions</span></TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {query.isLoading
                ? Array.from({ length: 3 }).map((_, index) => <TableRow key={index}><TableCell colSpan={6}><Skeleton className="h-6 w-full" /></TableCell></TableRow>)
                : types.map((type) => {
                    const series = type.series.find((candidate) => candidate.is_default) ?? type.series[0];
                    return (
                      <TableRow key={type.key}>
                        <TableCell>
                          <div className="font-medium">{type.label}</div>
                          <div className="text-xs text-muted-foreground">Numbered {type.official_when}</div>
                        </TableCell>
                        <TableCell className="font-mono text-sm">{series?.version?.template ?? type.default_template}{type.series.length > 1 && <span className="ml-2 font-sans text-xs text-muted-foreground">+{type.series.length - 1} more series</span>}</TableCell>
                        <TableCell className="font-mono text-sm font-semibold">{series?.example ?? '—'}</TableCell>
                        <TableCell className="text-sm">{periodLabel(series?.version?.scope_period ?? type.default_scope_period)}</TableCell>
                        <TableCell><Badge variant="secondary">{CONTINUITY_LABELS[series?.version?.continuity ?? type.default_continuity]}</Badge></TableCell>
                        <TableCell className="text-right">
                          <Button type="button" size="sm" variant="outline" aria-label={`${type.can_manage ? 'Edit' : 'View'} ${type.label} numbering`} onClick={() => navigate(`/settings/document-numbering/${type.key}`)}>
                            {type.can_manage ? 'Edit' : 'View'}
                          </Button>
                        </TableCell>
                      </TableRow>
                    );
                  })}
            </TableBody>
          </Table>
        </div>
      )}
      {showConstants && <NumberingConstantsPanel />}
      <Outlet />
    </div>
  );
}

/** One document type's editor, as a side sheet over the list (the child route). */
export function NumberingEditorScreen() {
  const navigate = useNavigate();
  const { type: typeKey } = useParams<{ type: string }>();
  const { query, types } = useNumberingTypes();
  const [options, setOptions] = useState<AdvancedOption[]>([]);
  const type = types.find((candidate) => candidate.key === typeKey);

  return (
    <RecordSheet
      title={type ? `${type.label} numbers` : 'Document numbering'}
      description={type ? `How ${type.label.toLowerCase()} numbers are built. Taken ${type.official_when}.` : undefined}
      icon={<Hash />}
      size="xl"
      dismissOnOutsideClick={!type?.can_manage}
      actions={type ? <AdvancedOptionsMenu options={options} /> : undefined}
      onClose={() => navigate('/settings/document-numbering')}
    >
      {query.isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : !type ? (
        <p className="text-sm text-destructive">That document type is not registered for numbering.</p>
      ) : (
        <NumberFormatEditor key={type.key} type={type} onAdvancedOptionsChange={setOptions} />
      )}
    </RecordSheet>
  );
}
