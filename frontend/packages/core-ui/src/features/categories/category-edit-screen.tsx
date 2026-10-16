import { useState } from 'react';
import { useLocation, useNavigate, useParams } from 'react-router';
import type { UseFormReturn } from 'react-hook-form';
import { useCategoriesShow, useCategoriesUpdate } from '@erp/core-api';
import { Button } from '../../components/ui/button';
import { Skeleton } from '../../components/ui/skeleton';
import { PageHeader } from '../../components/page/page-header';
import { ScreenTemplate } from '../../components/page/screen-template';
import { RecordSheet } from '../../components/page/record-sheet';
import { applyApiErrorsToForm } from '../../lib/api-errors';
import type { Presentation } from '../../lib/presentation';
import { CATEGORY_FORM_FIELDS, CategoryForm, categoryPayload, type CategoryFormValues } from './category-form';
import { usePermission } from '../../hooks/use-permission';

const optionalId = (value: number | null | undefined) => (value == null ? 'NONE' : String(value));

export interface CategoryEditScreenProps {
  presentation?: Presentation;
}

export function CategoryEditScreen({ presentation = 'page' }: CategoryEditScreenProps) {
  const id = Number(useParams<{ id: string }>().id);
  const mayManage = usePermission('inventory.category.manage');
  const mayManageMappings = usePermission('accounting.account-mapping.manage');
  const navigate = useNavigate();
  const { search } = useLocation();
  const query = useCategoriesShow(id);
  const category = query.data?.status === 200 ? query.data.data.data : undefined;
  const mutation = useCategoriesUpdate();
  const [error, setError] = useState<string | null>(null);

  const handleClose = () => {
    navigate({ pathname: `/categories/${id}`, search });
  };

  if (!mayManage) {
    if (presentation === 'sheet') {
      return (
        <RecordSheet title="Access denied" size="lg" className="erp-record" onClose={handleClose}>
          <div className="space-y-4 pb-4">
            <p className="text-sm text-destructive">You don't have permission to edit categories.</p>
            <Button variant="outline" onClick={handleClose}>
              Back to category
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
            backTo={`/categories/${id}${search}`}
          />
          <p className="text-sm text-destructive">You don't have permission to edit categories.</p>
          <Button variant="outline" onClick={handleClose}>
            Back to category
          </Button>
        </div>
      </ScreenTemplate>
    );
  }

  const submit = (values: CategoryFormValues, form: UseFormReturn<CategoryFormValues>) => {
    setError(null);
    mutation.mutate(
      { category: id, data: categoryPayload(values, mayManageMappings) },
      {
        onSuccess: (r) => {
          if (r.status === 200) navigate({ pathname: `/categories/${r.data.data.id}`, search });
        },
        onError: (e) => setError(applyApiErrorsToForm(e, form.setError, CATEGORY_FORM_FIELDS)),
      },
    );
  };

  if (query.isLoading) {
    if (presentation === 'sheet') {
      return (
        <RecordSheet title="Loading category…" size="lg" className="erp-record" onClose={handleClose}>
          <div className="space-y-4 pb-4">
            <Skeleton className="h-9 w-64" />
            <Skeleton className="h-64 w-full" />
          </div>
        </RecordSheet>
      );
    }
    return (
      <ScreenTemplate kind="form" className="erp-screen">
        <div className="space-y-4">
          <Skeleton className="h-9 w-64" />
          <Skeleton className="h-64 w-full" />
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
              Back to category
            </Button>
          </div>
        </RecordSheet>
      );
    }
    return (
      <ScreenTemplate kind="form" className="erp-screen">
        <div className="space-y-4">
          <p className="text-sm text-destructive">Couldn't load this category.</p>
          <Button variant="outline" onClick={() => navigate({ pathname: '/categories', search })}>
            Back to categories
          </Button>
        </div>
      </ScreenTemplate>
    );
  }

  const formBody = (
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
      onCancel={handleClose}
      isSubmitting={mutation.isPending}
      formError={error}
      mode="edit"
    />
  );

  if (presentation === 'sheet') {
    return (
      <RecordSheet
        title={`Edit ${category.name}`}
        description={category.code}
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
          title={`Edit ${category.name}`}
          description={category.code}
          breadcrumbs={[
            { label: 'Categories', to: `/categories${search}` },
            { label: category.code, to: `/categories/${id}${search}` },
            { label: 'Edit' },
          ]}
          backTo={`/categories/${id}${search}`}
        />
        {formBody}
      </div>
    </ScreenTemplate>
  );
}

