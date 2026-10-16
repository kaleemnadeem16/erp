import { useState } from 'react';
import { ArrowLeft, Pencil, Trash2 } from 'lucide-react';
import { Link, useLocation, useNavigate, useParams } from 'react-router';
import { useCategoriesDestroy, useCategoriesShow, useChartOfAccountsIndex } from '@erp/core-api';
import { Button } from '../../components/ui/button';
import { Skeleton } from '../../components/ui/skeleton';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '../../components/ui/dialog';
import { DetailField } from '../../components/detail/detail-field';
import { DetailPageLayout, DetailSection } from '../../components/detail/detail-page-layout';
import { PageHeader } from '../../components/page/page-header';
import { ScreenTemplate } from '../../components/page/screen-template';
import { RecordSheet } from '../../components/page/record-sheet';
import { DetailPrintLayout, PrintTrigger, useQuickPrint, type DetailPrintSection } from '../../components/print';
import { getApiErrorMessage } from '../../lib/api-errors';
import { formatDateTime } from '../../lib/format-date';
import type { Presentation } from '../../lib/presentation';
import { usePermission } from '../../hooks/use-permission';

export interface CategoryDetailScreenProps {
  presentation?: Presentation;
}

export function CategoryDetailScreen({ presentation = 'page' }: CategoryDetailScreenProps) {
  const mayManage = usePermission('inventory.category.manage');
  const mayViewMappings = usePermission('accounting.account-mapping.view');
  const mayManageMappings = usePermission('accounting.account-mapping.manage');
  const showMappings = mayViewMappings || mayManageMappings;
  const id = Number(useParams<{ id: string }>().id);
  const navigate = useNavigate();
  const { search } = useLocation();
  const query = useCategoriesShow(id, { include: 'parent,children' });
  const category = query.data?.status === 200 ? query.data.data.data : undefined;
  const accountsQuery = useChartOfAccountsIndex({ per_page: 100, sort: 'code' }, { query: { enabled: showMappings } });
  const accounts = accountsQuery.data?.status === 200 ? accountsQuery.data.data.data : [];
  const account = (accountId: number | null | undefined) => accounts.find((a) => a.id === accountId);

  const [deleting, setDeleting] = useState(false);
  const [deleteError, setDeleteError] = useState<string | null>(null);
  const destroyMutation = useCategoriesDestroy();

  const handleClose = () => {
    navigate({ pathname: '/categories', search });
  };

  const handleDelete = () => {
    setDeleteError(null);
    destroyMutation.mutate(
      { category: id },
      {
        onSuccess: () => {
          setDeleting(false);
          navigate({ pathname: '/categories', search });
        },
        onError: (err) => {
          setDeleteError(getApiErrorMessage(err) || 'Could not delete category.');
        },
      },
    );
  };

  const { contentRef, print } = useQuickPrint({
    documentTitle: category ? `Category ${category.code}` : 'Category',
  });

  if (query.isLoading) {
    if (presentation === 'sheet') {
      return (
        <RecordSheet title="Loading category…" size="lg" className="erp-record" onClose={handleClose}>
          <div className="space-y-4 pb-4">
            <Skeleton className="h-9 w-64" />
            <Skeleton className="h-48 w-full" />
          </div>
        </RecordSheet>
      );
    }
    return (
      <ScreenTemplate kind="detail" className="erp-screen">
        <div className="space-y-4">
          <Skeleton className="h-9 w-64" />
          <Skeleton className="h-48 w-full" />
        </div>
      </ScreenTemplate>
    );
  }

  if (!category || query.isError) {
    if (presentation === 'sheet') {
      return (
        <RecordSheet title="Category not found" size="lg" className="erp-record" onClose={handleClose}>
          <div className="space-y-4 pb-4">
            <p className="text-sm text-destructive">Couldn't load this category.</p>
            <Button variant="outline" onClick={handleClose}>
              Back to categories
            </Button>
          </div>
        </RecordSheet>
      );
    }
    return (
      <ScreenTemplate kind="detail" className="erp-screen">
        <div className="space-y-4">
          <p className="text-sm text-destructive">Couldn't load this category.</p>
          <Button variant="outline" onClick={() => navigate({ pathname: '/categories', search })}>
            <ArrowLeft className="h-4 w-4 mr-1.5" />
            Back to categories
          </Button>
        </div>
      </ScreenTemplate>
    );
  }

  const display = (accountId: number | null | undefined) => {
    const a = account(accountId);
    return a ? `${a.code} — ${a.name}` : undefined;
  };

  const printSections: DetailPrintSection[] = [
    {
      title: 'General',
      fields: [
        { label: 'Code', value: category.code },
        { label: 'Name', value: category.name },
        { label: 'Parent', value: category.parent?.name ?? '' },
        {
          label: 'Default valuation',
          value: category.default_valuation_method ?? 'Inherited',
        },
      ],
    },
    ...(showMappings && 'inventory_account_id' in category
      ? [
          {
            title: 'GL account defaults',
            fields: [
              {
                label: 'Income account',
                value: display(category.income_account_id) ?? 'Inherited',
              },
              {
                label: 'Expense account',
                value: display(category.expense_account_id) ?? 'Inherited',
              },
              {
                label: 'Inventory account',
                value: display(category.inventory_account_id) ?? 'Inherited',
              },
            ],
          },
        ]
      : []),
  ];

  const headerActions = (
    <>
      <PrintTrigger onPrint={print} />
      {mayManage && (
        <>
          <Button asChild size="sm">
            <Link to={{ pathname: `/categories/${id}/edit`, search }}>
              <Pencil className="h-4 w-4 mr-1.5" />
              Edit
            </Link>
          </Button>
          <Button
            variant="outline"
            size="sm"
            onClick={() => {
              setDeleteError(null);
              setDeleting(true);
            }}
          >
            <Trash2 className="h-4 w-4 mr-1.5" />
            Delete
          </Button>
        </>
      )}
    </>
  );

  const detailBody = (
    <div className="space-y-4">
      <DetailPageLayout>
        <DetailSection title="General">
          <DetailField label="Code">{category.code}</DetailField>
          <DetailField label="Name">{category.name}</DetailField>
          <DetailField label="Parent">
            {category.parent_id ? (
              <Link
                className="ui-order-link hover:underline"
                to={{ pathname: `/categories/${category.parent_id}`, search }}
              >
                {category.parent?.name ?? `Category #${category.parent_id}`}
              </Link>
            ) : (
              <span className="text-muted-foreground">None (Top-level category)</span>
            )}
          </DetailField>
          <DetailField label="Hierarchy role">
            {category.parent_id ? 'Subcategory' : 'Top-level category'}
          </DetailField>
          <DetailField label="Default valuation">{category.default_valuation_method ?? 'Inherited'}</DetailField>
          <DetailField label="Subcategories">
            {category.children && category.children.length > 0
              ? `${category.children.length} direct ${category.children.length === 1 ? 'subcategory' : 'subcategories'}`
              : '0 (Leaf category)'}
          </DetailField>
          <DetailField label="Created">{formatDateTime(category.created_at)}</DetailField>
          <DetailField label="Last updated">{formatDateTime(category.updated_at)}</DetailField>
        </DetailSection>
        {showMappings && 'inventory_account_id' in category && (
          <DetailSection title="GL account defaults">
            <DetailField label="Income account">
              {display(category.income_account_id) ?? (
                <span className="text-muted-foreground italic text-xs">Inherited</span>
              )}
            </DetailField>
            <DetailField label="Expense account">
              {display(category.expense_account_id) ?? (
                <span className="text-muted-foreground italic text-xs">Inherited</span>
              )}
            </DetailField>
            <DetailField label="Inventory account">
              {display(category.inventory_account_id) ?? (
                <span className="text-muted-foreground italic text-xs">Inherited</span>
              )}
            </DetailField>
          </DetailSection>
        )}
        <DetailSection title="Child categories" fullWidth>
          {category.children && category.children.length > 0 ? (
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 md:grid-cols-3">
              {category.children.map((child) => (
                <Link
                  key={child.id}
                  to={{ pathname: `/categories/${child.id}`, search }}
                  className="rounded-md border border-border bg-card p-3 hover:bg-accent transition-colors block"
                >
                  <span className="font-medium ui-order-link text-sm block">{child.name}</span>
                  <span className="text-xs text-muted-foreground">{child.code}</span>
                </Link>
              ))}
            </div>
          ) : (
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 rounded-md border border-dashed p-4 text-sm">
              <p className="text-muted-foreground">No child categories. This is a leaf category in the item hierarchy.</p>
              {mayManage && (
                <Button variant="outline" size="sm" asChild>
                  <Link to={{ pathname: '/categories/new', search }}>
                    Add subcategory
                  </Link>
                </Button>
              )}
            </div>
          )}
        </DetailSection>
        <DetailSection title="Items in this category" fullWidth>
          <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-sm">
            <p className="text-muted-foreground">
              Items assigned to <strong>{category.name}</strong> inherit its default valuation and GL account mappings.
            </p>
            <Button variant="outline" size="sm" asChild>
              <Link to={{ pathname: '/items', search: `?filter[category_id]=${category.id}` }}>
                View items in this category →
              </Link>
            </Button>
          </div>
        </DetailSection>
      </DetailPageLayout>

      <Dialog open={deleting} onOpenChange={setDeleting}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Delete category</DialogTitle>
            <DialogDescription>
              Are you sure you want to delete category <strong>{category.name}</strong> ({category.code})?
              Categories referenced by items or child categories cannot be deleted.
            </DialogDescription>
          </DialogHeader>
          {deleteError && (
            <div className="rounded-md border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive font-medium">
              {deleteError}
            </div>
          )}
          <DialogFooter>
            <Button variant="outline" onClick={() => setDeleting(false)} disabled={destroyMutation.isPending}>
              Cancel
            </Button>
            <Button variant="destructive" onClick={handleDelete} disabled={destroyMutation.isPending}>
              {destroyMutation.isPending ? 'Deleting…' : 'Delete category'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <div className="hidden">
        <div ref={contentRef}>
          <DetailPrintLayout title={`CATEGORY: ${category.code}`} printedAt={new Date()} sections={printSections} />
        </div>
      </div>
    </div>
  );

  if (presentation === 'sheet') {
    return (
      <RecordSheet
        title={category.name}
        description={category.code}
        actions={headerActions}
        size="lg"
        className="erp-record"
        onClose={handleClose}
      >
        <div className="space-y-4 pb-4">{detailBody}</div>
      </RecordSheet>
    );
  }

  return (
    <ScreenTemplate kind="detail" className="erp-screen">
      <div className="space-y-4">
        <PageHeader
          title={category.name}
          description={category.code}
          breadcrumbs={[{ label: 'Categories', to: `/categories${search}` }, { label: category.code }]}
          backTo={`/categories${search}`}
          actions={headerActions}
        />
        {detailBody}
      </div>
    </ScreenTemplate>
  );
}

