import { useCompanyShow } from '@erp/core-api';

import { asCompanyView } from './company-types';

/** The company name from the authoritative profile (Settings → Company), or undefined until it has loaded. */
export function useCompanyName(): string | undefined {
  const query = useCompanyShow({ query: { staleTime: 5 * 60_000 } });
  const name = query.data?.status === 200 ? asCompanyView(query.data.data.data)?.values.name : undefined;
  return typeof name === 'string' && name !== '' ? name : undefined;
}
