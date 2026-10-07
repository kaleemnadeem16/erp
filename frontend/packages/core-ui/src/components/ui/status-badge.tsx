import type { ReactNode } from "react";
import { Badge } from "./badge";
import { cn } from "../../lib/utils";

export type StatusTone =
  "draft" | "pending" | "active" | "progress" | "success" | "danger";

/** Domains choose the tone; the component always keeps the visible state label. */
export function StatusBadge({
  tone = "draft",
  children,
  className,
}: {
  tone?: StatusTone;
  children: ReactNode;
  className?: string;
}) {
  return (
    <Badge
      variant="secondary"
      className={cn("ui-status-badge", `ui-status-${tone}`, className)}
    >
      {children}
    </Badge>
  );
}
