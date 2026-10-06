import { useAuthStore } from "../stores/auth-store";

/**
 * Whoever may read a recipe: formulation inputs and quantities, application rates, assembly components (ADR 0086). Mirrors the backend's
 * RecipeReadAccess, which is authoritative; this only decides what to ask for and show.
 */
export const RECIPE_READ_PERMISSIONS = [
  "estimating.recipe.view",
  "estimating.formulation.manage",
  "estimating.application-rate.manage",
  "estimating.assembly.manage",
  "estimating.material_preview.run",
  "estimating.internal_cost.view",
] as const;

export function useRecipeAccess(): boolean {
  const user = useAuthStore((state) => state.user);

  return (
    Array.isArray(user?.permissions) &&
    RECIPE_READ_PERMISSIONS.some((permission) =>
      user.permissions.includes(permission),
    )
  );
}
