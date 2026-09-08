import { useState } from 'react';
import { useNavigate, useParams } from 'react-router';
import type { UseFormReturn } from 'react-hook-form';
import { useCategoriesShow, useCategoriesUpdate } from '@erp/core-api';
import { Button } from '../../components/ui/button';
import { Skeleton } from '../../components/ui/skeleton';
import { PageHeader } from '../../components/page/page-header';
import { applyApiErrorsToForm } from '../../lib/api-errors';
import { CATEGORY_FORM_FIELDS, CategoryForm, categoryPayload, type CategoryFormValues } from './category-form';
const optionalId = (value: number | null) => (value == null ? 'NONE' : String(value));

export function CategoryEditScreen() {
  const id = Number(useParams<{ id: string }>().id);
  const navigate = useNavigate();
  const query = useCategoriesShow(id);
  const category = query.data?.status === 200 ? query.data.data.data : undefined;
  const mutation = useCategoriesUpdate();
  const [error, setError] = useState<string | null>(null);
  const submit = (values: CategoryFormValues, form: UseFormReturn<CategoryFormValues>) => {
    setError(null);
    mutation.mutate(
      { category: id, data: categoryPayload(values) },
      {
        onSuccess: (r) => {
          if (r.status === 200) navigate(`/categories/${r.data.data.id}`);
        },
        onError: (e) => setError(applyApiErrorsToForm(e, form.setError, CATEGORY_FORM_FIELDS)),
      }
    );
  };
  if (query.isLoading)
    return (
      <div className="space-y-4 p-6">
        <Skeleton className="h-9 w-64" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  if (!category || query.isError)
    return (
      <div className="p-6">
        <Button variant="outline" onClick={() => navigate('/categories')}>
          Back to categories
        </Button>
      </div>
    );
  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title={`Edit ${category.name}`}
        description={category.code}
        breadcrumbs={[
          { label: 'Categories', to: '/categories' },
          { label: category.code, to: `/categories/${id}` },
          { label: 'Edit' },
        ]}
      />
      <CategoryForm
        excludeId={id}
        defaultValues={{
          code: category.code,
          name: category.name,
          parent_id: optionalId(category.parent_id),
          default_valuation_method: category.default_valuation_method ?? 'NONE',
          income_account_id: optionalId(category.income_account_id),
          expense_account_id: optionalId(category.expense_account_id),
          inventory_account_id: optionalId(category.inventory_account_id),
        }}
        onSubmit={submit}
        isSubmitting={mutation.isPending}
        formError={error}
      />
    </div>
  );
}
