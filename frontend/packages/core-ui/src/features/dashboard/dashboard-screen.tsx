import { useAuthStore } from '../../stores/auth-store';

export function DashboardScreen() {
  const user = useAuthStore((state) => state.user);

  return (
    <div className="p-6">
      <h1 className="text-xl font-semibold">Dashboard</h1>
      <p className="mt-2 text-muted-foreground">
        Welcome{user ? `, ${user.name}` : ''} — this is the one authenticated page the Phase 0
        skeleton ships with.
      </p>
    </div>
  );
}
