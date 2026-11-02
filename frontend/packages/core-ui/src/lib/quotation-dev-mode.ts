/** Frontend exposure only. Backend authority and its separate local-dev opt-in stay authoritative. */
export interface QuotationDevContext {
  development: boolean;
  flag: string | undefined;
  hostname: string;
}

/** A production build can never enable this, even if its env flag is accidentally set. */
export function allowsQuotationDevMode({
  development,
  flag,
  hostname,
}: QuotationDevContext): boolean {
  return (
    development &&
    flag === "1" &&
    ["localhost", "127.0.0.1", "[::1]", "::1"].includes(hostname)
  );
}

let enabled = false;
/** Called once by the app bootstrap with Vite's built-in DEV and the opt-in env value. */
export function configureQuotationDevMode(context: QuotationDevContext): void {
  enabled = allowsQuotationDevMode(context);
}
export function quotationDevModeEnabled(): boolean {
  return enabled;
}
