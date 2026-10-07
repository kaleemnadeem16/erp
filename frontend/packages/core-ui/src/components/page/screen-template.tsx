import {
  createContext,
  useContext,
  useMemo,
  type CSSProperties,
  type ReactNode,
} from "react";
import { cn } from "../../lib/utils";

/** CSS variables only: overrides stay local and cannot change permissions or workflow rules. */
export type ScreenDesignVariables = { [key: `--${string}`]: string | number };
const ScreenDesignContext = createContext<ScreenDesignVariables | null>(null);

export function ScreenDesignProvider({
  variables,
  children,
}: {
  variables?: ScreenDesignVariables;
  children: ReactNode;
}) {
  const inherited = useContext(ScreenDesignContext);
  const value = useMemo(
    () => ({ ...inherited, ...variables }),
    [inherited, variables],
  );
  return (
    <ScreenDesignContext.Provider value={value}>
      {children}
    </ScreenDesignContext.Provider>
  );
}

/** Radix portals preserve React context, but lose CSS inheritance. Forward the explicit overrides to their DOM root. */
export function useScreenDesign() {
  return useContext(ScreenDesignContext);
}

export const SCREEN_TEMPLATES = {
  list: "erp-screen",
  detail: "erp-screen erp-record",
  form: "erp-screen erp-record",
} as const;
export type ScreenTemplateKind = keyof typeof SCREEN_TEMPLATES;

export interface ScreenTemplateProps {
  kind: ScreenTemplateKind;
  header?: ReactNode;
  children: ReactNode;
  className?: string;
  variables?: ScreenDesignVariables;
}

/** Shared page shell. Compose it with PageHeader, DetailSection, DataTable or WizardShell; the feature owns data and actions. */
export function ScreenTemplate({
  kind,
  header,
  children,
  className,
  variables,
}: ScreenTemplateProps) {
  const inherited = useScreenDesign();
  const style = { ...inherited, ...variables } as CSSProperties;
  return (
    <ScreenDesignProvider variables={variables}>
      <div
        className={cn(SCREEN_TEMPLATES[kind], className)}
        data-screen-template={kind}
        style={style}
      >
        {header}
        {children}
      </div>
    </ScreenDesignProvider>
  );
}
