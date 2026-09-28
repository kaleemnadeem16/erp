import { useAuthStore } from '../stores/auth-store';

export function usePermission(permission: string): boolean {
  const user = useAuthStore((state) => state.user);

  return Array.isArray(user?.permissions) && user.permissions.includes(permission);
}
