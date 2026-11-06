import { StatusBadge, type StatusTone } from '../../components/ui/status-badge';
import { humanizeCode } from '../../components/data-table';

/** The lifecycle shared by formulations and assemblies: a recipe is drafted, goes live, and is archived (never deleted once used). */
export const RECIPE_STATUSES = ['DRAFT', 'ACTIVE', 'ARCHIVED'] as const;

const TONE: Record<string, StatusTone> = { DRAFT: 'draft', ACTIVE: 'active', ARCHIVED: 'pending', SUPERSEDED: 'pending' };

export function RecipeStatusBadge({ status }: { status: string }) {
  return <StatusBadge tone={TONE[status] ?? 'draft'}>{humanizeCode(status)}</StatusBadge>;
}
