import type { MouseEvent, ReactNode } from "react";
import { cn } from "../../lib/utils";

export interface RecordLinkProps {
  children: ReactNode;
  onClick?: (event: MouseEvent<HTMLSpanElement>) => void;
  className?: string;
  title?: string;
}

/**
 * Standard table record primary link matching the PO standard.
 * Uses semantic CSS tokens (.ui-order-link) with identical color variables,
 * font weights, and hover underline transitions across all modules and themes.
 */
export function RecordLink({
  children,
  onClick,
  className,
  title,
}: RecordLinkProps) {
  return (
    <span
      role={onClick ? "button" : undefined}
      tabIndex={onClick ? 0 : undefined}
      onClick={onClick}
      onKeyDown={
        onClick
          ? (e) => {
              if (e.key === "Enter" || e.key === " ") {
                e.preventDefault();
                onClick(e as unknown as MouseEvent<HTMLSpanElement>);
              }
            }
          : undefined
      }
      title={title}
      className={cn("ui-order-link cursor-pointer", className)}
    >
      {children}
    </span>
  );
}
