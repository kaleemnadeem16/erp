import { useState } from 'react';
import { useNavigate } from 'react-router';
import type { UseFormReturn } from 'react-hook-form';
import { useCategoriesStore } from '@erp/core-api';
import { PageHeader } from '../../components/page/page-header';
import { applyApiErrorsToForm } from '../../lib/api-errors';
import { CATEGORY_FORM_FIELDS, CategoryForm, categoryPayload, type CategoryFormValues } from './category-form';
import { usePermission } from '../../hooks/use-permission';

export function CategoryCreateScreen() {
  const mayManageMappings = usePermission('accounting.account-mapping.manage');
  const navigate = useNavigate();
  const mutation = useCategoriesStore();
  const [error, setError] = useState<string | null>(null);
  const submit = (values: CategoryFormValues, form: UseFormReturn<CategoryFormValues>) => {
    setError(null);
    mutation.mutate(
      { data: categoryPayload(values, mayManageMappings) },
      {
        onSuccess: (r) => {
          if (r.status === 201) navigate(`/categories/${r.data.data.id}`);
        },
        onError: (e) => setError(applyApiErrorsToForm(e, form.setError, CATEGORY_FORM_FIELDS)),
      },
    );
  };
  return (
    <div className="space-y-4 p-6">
      <PageHeader
        title="New category"
        description="Create an item category and its inherited defaults."
        breadcrumbs={[{ label: 'Categories', to: '/categories' }, { label: 'New' }]}
      />
      <CategoryForm onSubmit={submit} isSubmitting={mutation.isPending} formError={error} />
    </div>
  );
}
