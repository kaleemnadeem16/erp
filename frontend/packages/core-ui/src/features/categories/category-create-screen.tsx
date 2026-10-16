import { useState } from 'react';
import { useLocation, useNavigate } from 'react-router';
import type { UseFormReturn } from 'react-hook-form';
import { useCategoriesStore } from '@erp/core-api';
import { Button } from '../../components/ui/button';
import { PageHeader } from '../../components/page/page-header';
import { ScreenTemplate } from '../../components/page/screen-template';
import { RecordSheet } from '../../components/page/record-sheet';
import { applyApiErrorsToForm } from '../../lib/api-errors';
import type { Presentation } from '../../lib/presentation';
import { CATEGORY_FORM_FIELDS, CategoryForm, categoryPayload, type CategoryFormValues } from './category-form';
import { usePermission } from '../../hooks/use-permission';

export interface CategoryCreateScreenProps {
  presentation?: Presentation;
}

export function CategoryCreateScreen({ presentation = 'page' }: CategoryCreateScreenProps) {
  const mayManage = usePermission('inventory.category.manage');
  const mayManageMappings = usePermission('accounting.account-mapping.manage');
  const navigate = useNavigate();
  const { search } = useLocation();
  const mutation = useCategoriesStore();
  const [error, setError] = useState<string | null>(null);

  const handleClose = () => {
    navigate({ pathname: '/categories', search });
  };

  if (!mayManage) {
    if (presentation === 'sheet') {
      return (
        <RecordSheet title="Access denied" size="lg" className="erp-record" onClose={handleClose}>
          <div className="space-y-4 pb-4">
            <p className="text-sm text-destructive">You don't have permission to create categories.</p>
            <Button variant="outline" onClick={handleClose}>
              Back to categories
            </Button>
          </div>
        </RecordSheet>
      );
    }
    return (
      <ScreenTemplate kind="form" className="erp-screen">
        <div className="space-y-4">
          <PageHeader
            title="Access denied"
            breadcrumbs={[{ label: 'Categories', to: `/categories${search}` }]}
            backTo={`/categories${search}`}
          />
          <p className="text-sm text-destructive">You don't have permission to create categories.</p>
          <Button variant="outline" onClick={handleClose}>
            Back to categories
          </Button>
        </div>
      </ScreenTemplate>
    );
  }

  const submit = (values: CategoryFormValues, form: UseFormReturn<CategoryFormValues>) => {
    setError(null);
    mutation.mutate(
      { data: categoryPayload(values, mayManageMappings) },
      {
        onSuccess: (r) => {
          if (r.status === 201) navigate({ pathname: `/categories/${r.data.data.id}`, search });
        },
        onError: (e) => setError(applyApiErrorsToForm(e, form.setError, CATEGORY_FORM_FIELDS)),
      },
    );
  };

  const formBody = (
    <CategoryForm
      mode="create"
      onSubmit={submit}
      onCancel={handleClose}
      isSubmitting={mutation.isPending}
      formError={error}
    />
  );

  if (presentation === 'sheet') {
    return (
      <RecordSheet
        title="New category"
        description="Create an item category and its inherited defaults."
        size="lg"
        className="erp-record"
        dismissOnOutsideClick={false}
        onClose={handleClose}
      >
        <div className="space-y-4 pb-4">{formBody}</div>
      </RecordSheet>
    );
  }

  return (
    <ScreenTemplate kind="form" className="erp-screen">
      <div className="space-y-4">
        <PageHeader
          title="New category"
          description="Create an item category and its inherited defaults."
          breadcrumbs={[{ label: 'Categories', to: `/categories${search}` }, { label: 'New' }]}
          backTo={`/categories${search}`}
        />
        {formBody}
      </div>
    </ScreenTemplate>
  );
}

