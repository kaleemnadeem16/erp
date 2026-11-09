import { useEffect, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { fetchBlob, getCompanyBrandingLogoUrl, getCompanyBrandingShowQueryKey, useCompanyBrandingShow, useCompanyBrandingUpdate } from '@erp/core-api';

import { DetailSection } from '../../components/detail/detail-page-layout';
import { Button } from '../../components/ui/button';
import { Input } from '../../components/ui/input';
import { Label } from '../../components/ui/label';
import { Textarea } from '../../components/ui/textarea';
import { describeLoadError, getApiErrorMessage, isApiError } from '../../lib/api-errors';

/** The shape of `GET /api/v1/company/branding`, written out by hand (the generated types are loose about nullable fields). */
export interface CompanyBrandingView {
  version: number | null;
  display_name: string | null;
  tagline: string | null;
  logo: { media_type: string; width: number; height: number; size_bytes: number } | null;
  reason: string | null;
  changed_at: string | null;
  enabled: boolean;
  can_manage: boolean;
  logo_max_bytes: number;
  logo_types: string[];
}

export function asBrandingView(data: unknown): CompanyBrandingView | undefined {
  return data && typeof data === 'object' ? (data as CompanyBrandingView) : undefined;
}

/** The logo problems the person can fix before anything is sent; the server checks the same things again, from the bytes. */
export function logoProblem(file: { size: number; type: string }, maxBytes: number): string | null {
  if (file.type !== 'image/png' && file.type !== 'image/jpeg') return 'The logo must be a PNG or a JPEG picture.';
  if (file.size > maxBytes) return `The logo is larger than ${Math.round(maxBytes / 1_048_576)} MB.`;
  return null;
}

/** The current logo, fetched with the session (it is private; no link is ever shown) and shown from a temporary object URL. */
function useLogoUrl(version: number | null, hasLogo: boolean): string | null {
  const query = useQuery({
    queryKey: ['company-branding-logo', version],
    queryFn: () => fetchBlob(getCompanyBrandingLogoUrl(), 'image/*'),
    enabled: hasLogo,
    staleTime: Infinity,
  });
  const [url, setUrl] = useState<string | null>(null);
  useEffect(() => {
    if (!query.data) {
      setUrl(null);
      return;
    }
    const made = URL.createObjectURL(query.data);
    setUrl(made);
    return () => URL.revokeObjectURL(made);
  }, [query.data]);
  return hasLogo ? url : null;
}

/**
 * Settings → Company → Branding: the display name, tagline and logo printed on documents (quotations today). Everyone signed in sees the current brand; changing it needs the
 * branding duty, which is not switched on yet, so most people see it read-only with the reason. Every change is a new numbered version with a reason; a logo is PNG or JPEG
 * up to 2 MB and is cleaned of metadata on the server. A document keeps the brand it was created with.
 */
export function CompanyBrandingSection() {
  const query = useCompanyBrandingShow({ query: { retry: false } });
  const brand = query.data?.status === 200 ? asBrandingView(query.data.data.data) : undefined;
  const update = useCompanyBrandingUpdate();
  const queryClient = useQueryClient();
  const logoUrl = useLogoUrl(brand?.version ?? null, brand?.logo != null);

  const [name, setName] = useState('');
  const [tagline, setTagline] = useState('');
  const [file, setFile] = useState<File | null>(null);
  const [removeLogo, setRemoveLogo] = useState(false);
  const [reason, setReason] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    setName(brand?.display_name ?? '');
    setTagline(brand?.tagline ?? '');
    // Re-sync only when a new saved version arrives.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [brand?.version]);

  const previewUrl = file ? URL.createObjectURL(file) : null;
  useEffect(() => () => { if (previewUrl) URL.revokeObjectURL(previewUrl); }, [previewUrl]);

  if (query.isLoading || !brand) {
    return (
      <DetailSection title="Branding" description="The name, tagline and logo printed on documents." fullWidth>
        {query.isError || !query.isLoading ? (
          <div role="alert" className="space-y-2 text-sm text-destructive">
            <p>{query.isError ? describeLoadError(query.error) : 'Branding could not be read.'}</p>
            <Button type="button" variant="outline" size="sm" className="min-h-11 md:min-h-9" onClick={() => void query.refetch()}>
              Try again
            </Button>
          </div>
        ) : (
          <p className="text-sm text-muted-foreground">Loading…</p>
        )}
      </DetailSection>
    );
  }

  const nameChanged = name.trim() !== (brand.display_name ?? '');
  const taglineChanged = tagline.trim() !== (brand.tagline ?? '');
  const changed = nameChanged || taglineChanged || file !== null || removeLogo;
  const editable = brand.can_manage;

  function pick(chosen: File | null) {
    setSaved(false);
    setError(null);
    if (chosen) {
      const problem = logoProblem(chosen, brand?.logo_max_bytes ?? 2_097_152);
      if (problem) {
        setFile(null);
        setError(problem);
        return;
      }
      setRemoveLogo(false);
    }
    setFile(chosen);
  }

  function save() {
    setError(null);
    setSaved(false);
    update.mutate(
      {
        data: {
          ...(nameChanged ? { display_name: name.trim() } : {}),
          ...(taglineChanged ? { tagline: tagline.trim() } : {}),
          ...(removeLogo ? { remove_logo: 'true' } : {}),
          ...(file ? { logo: file } : {}),
          reason: reason.trim(),
        } as never,
      },
      {
        onSuccess: () => {
          setFile(null);
          setRemoveLogo(false);
          setReason('');
          setSaved(true);
          void queryClient.invalidateQueries({ queryKey: getCompanyBrandingShowQueryKey() });
          void queryClient.invalidateQueries({ queryKey: ['company-branding-logo'] });
        },
        onError: (failure) => {
          const fieldErrors = isApiError(failure) ? failure.body?.errors : undefined;
          const first = fieldErrors ? Object.values(fieldErrors)[0]?.[0] : undefined;
          setError(first ?? getApiErrorMessage(failure));
        },
      },
    );
  }

  return (
    <DetailSection title="Branding" description="The name, tagline and logo printed at the top of documents such as quotations. A document keeps the branding it was created with." fullWidth>
      <div className="col-span-full space-y-4" data-testid="company-branding">
        {!editable && (
          <p className="text-sm text-muted-foreground" data-testid="branding-readonly">
            {brand.enabled ? "You don't have permission to change the branding." : 'Changing the branding is not switched on yet. What is shown here is what documents use.'}
          </p>
        )}
        <div className="flex flex-wrap items-center gap-4">
          <div className="flex h-24 w-48 items-center justify-center rounded-md border border-dashed border-border bg-muted/30 p-2" data-testid="branding-logo-frame">
            {file && previewUrl && !removeLogo ? (
              <img src={previewUrl} alt="The new logo, not saved yet" className="max-h-full max-w-full object-contain" />
            ) : logoUrl && !removeLogo ? (
              <img src={logoUrl} alt="The current company logo" className="max-h-full max-w-full object-contain" />
            ) : (
              <span className="text-xs text-muted-foreground">{removeLogo ? 'The logo will be removed.' : 'No logo. Documents print without one.'}</span>
            )}
          </div>
          {brand.logo && !file && (
            <p className="text-xs text-muted-foreground">
              {brand.logo.width} × {brand.logo.height} px, {brand.logo.media_type === 'image/png' ? 'PNG' : 'JPEG'}.
            </p>
          )}
        </div>
        <div className="grid gap-3 md:grid-cols-2">
          <div className="space-y-1">
            <Label htmlFor="branding-name">Name on documents</Label>
            <Input id="branding-name" value={name} maxLength={120} disabled={!editable} onChange={(event) => { setSaved(false); setName(event.target.value); }} />
            <p className="text-xs text-muted-foreground">Shown beside the logo. Leave empty to show none.</p>
          </div>
          <div className="space-y-1">
            <Label htmlFor="branding-tagline">Tagline</Label>
            <Input id="branding-tagline" value={tagline} maxLength={160} disabled={!editable} onChange={(event) => { setSaved(false); setTagline(event.target.value); }} />
          </div>
        </div>
        {editable && (
          <div className="space-y-3">
            <div className="space-y-1">
              <Label htmlFor="branding-logo">Logo</Label>
              <Input id="branding-logo" type="file" accept="image/png,image/jpeg" onChange={(event) => pick(event.target.files?.[0] ?? null)} />
              <p className="text-xs text-muted-foreground">PNG or JPEG, up to 2 MB and 1000 × 1000 pixels. It is cleaned of hidden data (such as camera details) when saved.</p>
            </div>
            {brand.logo && (
              <label className="flex items-center gap-2 text-sm">
                <input type="checkbox" checked={removeLogo} onChange={(event) => { setRemoveLogo(event.target.checked); if (event.target.checked) setFile(null); }} />
                Remove the current logo
              </label>
            )}
            <div className="space-y-1">
              <Label htmlFor="branding-reason">Reason for the change</Label>
              <Textarea id="branding-reason" value={reason} onChange={(event) => setReason(event.target.value)} placeholder="Why is the branding changing? It is kept in the history." />
            </div>
            {error && <div role="alert" className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">{error}</div>}
            <div className="flex items-center gap-3">
              <Button onClick={save} disabled={!changed || reason.trim().length < 3 || update.isPending} data-testid="branding-save">
                {update.isPending ? 'Saving…' : 'Save branding'}
              </Button>
              {saved && <span className="text-sm text-muted-foreground" role="status">Saved as a new version.</span>}
            </div>
          </div>
        )}
        {brand.version !== null && (
          <p className="text-xs text-muted-foreground">Version {brand.version}{brand.reason ? `: ${brand.reason}` : ''}</p>
        )}
      </div>
    </DetailSection>
  );
}
