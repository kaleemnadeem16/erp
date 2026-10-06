import { ArrowLeft, Pencil } from 'lucide-react';
import { Link, useNavigate, useParams } from 'react-router';
import { useCategoriesShow, useChartOfAccountsIndex } from '@erp/core-api';
import { Button } from '../../components/ui/button';
import { Skeleton } from '../../components/ui/skeleton';
import { DetailField } from '../../components/detail/detail-field';
import { DetailPageLayout, DetailSection } from '../../components/detail/detail-page-layout';
import { PageHeader } from '../../components/page/page-header';
import { DetailPrintLayout, PrintTrigger, useQuickPrint, type DetailPrintSection } from '../../components/print';
import { usePermission } from '../../hooks/use-permission';

export function CategoryDetailScreen() {
  const mayManage = usePermission('inventory.category.manage');
  const mayViewMappings = usePermission('accounting.account-mapping.view');
  const mayManageMappings = usePermission('accounting.account-mapping.manage');
  const showMappings = mayViewMappings || mayManageMappings;
  const id = Number(useParams<{ id: string }>().id);
  const navigate = useNavigate();
  const query = useCategoriesShow(id, { include: 'parent,children' });
  const category = query.data?.status === 200 ? query.data.data.data : undefined;
  const accountsQuery = useChartOfAccountsIndex({ per_page: 100, sort: 'code' }, { query: { enabled: showMappings } });
  const accounts = accountsQuery.data?.status === 200 ? accountsQuery.data.data.data : [];
  const account = (accountId: number | null | undefined) => accounts.find((a) => a.id === accountId);
  const { contentRef, print } = useQuickPrint({
    documentTitle: category ? `Category ${category.code}` : 'Category',
  });
  if (query.isLoading)
    return (
      <div className="space-y-4 p-6">
        <Skeleton className="h-9 w-64" />
        <Skeleton className="h-48 w-full" />
      </div>
    );
  if (!category || query.isError)
    return (
      <div className="p-6">
        <Button variant="outline" onClick={() => navigate('/categories')}>
          <ArrowLeft className="h-4 w-4" />
          Back to categories
        </Button>
      </div>
    );
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
  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title={category.name}
        description={category.code}
        breadcrumbs={[{ label: 'Categories', to: '/categories' }, { label: category.code }]}
        actions={
          <>
            <PrintTrigger onPrint={print} />
            {mayManage && (
              <Button asChild size="sm">
                <Link to={`/categories/${id}/edit`}>
                  <Pencil className="h-4 w-4" />
                  Edit
                </Link>
              </Button>
            )}
          </>
        }
      />
      <DetailPageLayout>
        <DetailSection title="General">
          <DetailField label="Code">{category.code}</DetailField>
          <DetailField label="Name">{category.name}</DetailField>
          <DetailField label="Parent">{category.parent?.name}</DetailField>
          <DetailField label="Default valuation">{category.default_valuation_method ?? 'Inherited'}</DetailField>
        </DetailSection>
        {showMappings && 'inventory_account_id' in category && (
          <DetailSection title="GL account defaults">
            <DetailField label="Income account">{display(category.income_account_id) ?? 'Inherited'}</DetailField>
            <DetailField label="Expense account">{display(category.expense_account_id) ?? 'Inherited'}</DetailField>
            <DetailField label="Inventory account">{display(category.inventory_account_id) ?? 'Inherited'}</DetailField>
          </DetailSection>
        )}
        {category.children && category.children.length > 0 && (
          <DetailSection title="Child categories">
            {category.children.map((child) => (
              <DetailField key={child.id} label={child.code}>
                <Link className="hover:underline" to={`/categories/${child.id}`}>
                  {child.name}
                </Link>
              </DetailField>
            ))}
          </DetailSection>
        )}
      </DetailPageLayout>
      <div className="hidden">
        <div ref={contentRef}>
          <DetailPrintLayout title={`CATEGORY: ${category.code}`} printedAt={new Date()} sections={printSections} />
        </div>
      </div>
    </div>
  );
}
